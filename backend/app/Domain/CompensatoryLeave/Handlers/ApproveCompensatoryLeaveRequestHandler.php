<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Support\CompensatoryLeaveWorkType;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveType;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 代休消化申請を承認する。承認時に (1) 失効日が近い確定済みGrantから優先的に消し込み、
 * (2) 対象日の勤怠(attendance_days.work_type)に代休区分を反映する
 * (ApproveSpecialLeaveRequestHandlerと同じ考え方)。
 *
 * @implements CommandHandler<ApproveCompensatoryLeaveRequest>
 */
class ApproveCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof ApproveCompensatoryLeaveRequest);

        $request = CompensatoryLeaveRequest::query()->findOrFail($command->compensatoryLeaveRequestId);

        if ($request->status !== CompensatoryLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの代休申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (CompensatoryLeaveController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $day = $this->reflectOnAttendanceDay($request);

        $isHourly = $request->leave_type === PaidLeaveType::HOURLY;
        $plan = $this->planConsumption($request, $isHourly);

        $aggregates = [
            CompensatoryLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = CompensatoryLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                compensatoryLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $isHourly ? 0.0 : $amount,
                usedMinutes: $isHourly ? (int) round($amount) : null,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        $request = CompensatoryLeaveRequest::query()->findOrFail($request->id);

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return $request;
    }

    private function reflectOnAttendanceDay(CompensatoryLeaveRequest $request): AttendanceDay
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

        $day->work_type = CompensatoryLeaveWorkType::toAttendanceWorkType($request->leave_type);
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
     * @return array<int, array{grant: CompensatoryLeaveGrant, amount: float}>
     */
    private function planConsumption(CompensatoryLeaveRequest $request, bool $isHourly): array
    {
        $column = $isHourly ? 'remaining_minutes' : 'remaining_days';
        $remainingToConsume = $isHourly ? (float) $request->requested_minutes : (float) $request->requested_days;
        $plan = [];

        $grants = CompensatoryLeaveGrant::query()
            ->availableOn($request->target_date->toDateString())
            ->where('user_id', $request->user_id)
            ->where('status', CompensatoryLeaveGrantStatus::CONFIRMED)
            ->where($column, '>', 0)
            // 失効日が近い付与分から優先的に消し込む。無期限(expires_on=null)の付与は最後に消化する。
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->{$column}, $remainingToConsume);
            $plan[] = ['grant' => $grant, 'amount' => $consume];
            $remainingToConsume -= $consume;
        }

        if ($remainingToConsume > 0) {
            throw new DomainRuleException('代休の残数が不足しているため承認できません。');
        }

        return $plan;
    }
}
