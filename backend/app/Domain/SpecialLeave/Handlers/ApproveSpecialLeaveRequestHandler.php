<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveGrantAggregate;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\SpecialLeave\SpecialLeaveWorkType;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 特別休暇を承認する。承認時に (1) 失効日が近い付与分(無期限は最後)から消化し、
 * (2) 対象日の勤怠(attendance_days.work_type)に特別休暇区分を反映する。
 * ApprovePaidLeaveRequestHandlerと同じ考え方だが、有給側のコードには一切依存しない
 * 独立した実装とする(有給は法定の要件を持つため)。複数集約トランザクション
 * (persistInTransaction)による消費計画の先確定も同様(ApprovePaidLeaveRequestHandler参照)。
 *
 * @implements CommandHandler<ApproveSpecialLeaveRequest>
 */
class ApproveSpecialLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ApproveSpecialLeaveRequest);

        $request = SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);

        if ($request->status !== SpecialLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの特別休暇申請のみ承認できます。');
        }

        if ($request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $day = $this->reflectOnAttendanceDay($request);

        $plan = $this->planConsumption($request);

        $usedMinutes = $request->hours !== null ? (int) round($request->hours * 60) : null;

        $aggregates = [
            SpecialLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = SpecialLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                specialLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $amount,
                usedMinutes: $usedMinutes,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        $request = SpecialLeaveRequest::query()->findOrFail($request->id);

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );

        return $request;
    }

    private function reflectOnAttendanceDay(SpecialLeaveRequest $request): AttendanceDay
    {
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        if ($day === null) {
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $request->user_id)
                ->whereDate('work_date', $request->target_date)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $request->user_id,
                'work_date' => $request->target_date,
                'shift_assignment_id' => $shiftAssignment?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::MANUAL,
            ]);
        }

        $day->work_type = SpecialLeaveWorkType::toAttendanceWorkType($request->leave_type);
        if ($request->leave_type === PaidLeaveType::FULL) {
            // 全休は出退勤操作が発生しないため、締め忘れとして警告されないよう完了扱いにする。
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        return $day;
    }

    /**
     * 消化計画を確定する。この時点ではまだイベントを記録しない
     * (残数不足の場合に一部だけ記録されてしまう不整合を避けるため)。
     *
     * @return array<int, array{grant: SpecialLeaveGrant, amount: float}>
     */
    private function planConsumption(SpecialLeaveRequest $request): array
    {
        $remainingToConsume = (float) $request->requested_days;
        $plan = [];

        $grants = SpecialLeaveGrant::query()
            ->availableOn($request->target_date->toDateString())
            ->where('user_id', $request->user_id)
            ->where('special_leave_type_id', $request->special_leave_type_id)
            ->where('remaining_days', '>', 0)
            // 失効日が近い付与分から優先的に消し込む。無期限(expires_on=null)の付与は最後に消化する。
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->remaining_days, $remainingToConsume);
            $plan[] = ['grant' => $grant, 'amount' => $consume];
            $remainingToConsume -= $consume;
        }

        if ($remainingToConsume > 0) {
            throw new DomainRuleException('特別休暇の残数が不足しているため承認できません。');
        }

        return $plan;
    }
}
