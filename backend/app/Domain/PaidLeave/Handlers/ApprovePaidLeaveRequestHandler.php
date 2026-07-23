<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * UC-P004: 有給を承認する。承認時に (1) 有効期限が近い付与分から消化し、
 * (2) 対象日の勤怠(attendance_days.work_type)に有給区分を反映する。
 *
 * 承認1件で「paid_leave_request集約の承認」と「1件以上のpaid_leave_grant集約の消化」に
 * またがるため、`AggregateRoot::persistInTransaction()`で1トランザクションにまとめて記録する
 * (DeviceAdminSessionOpenerに次ぐ2例目の複数集約トランザクション。
 * docs/29-event-sourcing-framework-migration.md参照)。消費計画(planConsumption)を
 * 先に確定させ、残数不足なら集約を一切記録せずに例外を投げる形にしたため、
 * 旧実装にあった「不足判定前に一部grantへ消化を反映してしまう」不整合も併せて解消した。
 *
 * @implements CommandHandler<ApprovePaidLeaveRequest>
 */
class ApprovePaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ApprovePaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの有給申請のみ承認できます。');
        }

        if ($request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $day = $this->reflectOnAttendanceDay($request);

        $plan = $this->planConsumption($request);

        $usedMinutes = $request->hours !== null ? (int) round($request->hours * 60) : null;

        $aggregates = [
            PaidLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = PaidLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                paidLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $amount,
                usedMinutes: $usedMinutes,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        $request = PaidLeaveRequest::query()->findOrFail($request->id);

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return $request;
    }

    private function reflectOnAttendanceDay(PaidLeaveRequest $request): AttendanceDay
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

        $day->work_type = PaidLeaveType::toAttendanceWorkType($request->leave_type);
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
     * @return array<int, array{grant: PaidLeaveGrant, amount: float}>
     */
    private function planConsumption(PaidLeaveRequest $request): array
    {
        $remainingToConsume = (float) $request->requested_days;
        $plan = [];

        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user_id)
            ->whereDate('expires_on', '>=', $request->target_date)
            ->where('remaining_days', '>', 0)
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
            throw new DomainRuleException('有給残数が不足しているため承認できません。');
        }

        return $plan;
    }
}
