<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use Illuminate\Support\Carbon;

/**
 * UC-P003: 有給を申請する。
 *
 * @implements CommandHandler<RequestPaidLeave>
 */
class RequestPaidLeaveHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof RequestPaidLeave);

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->with('workStyle')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        if ($shiftAssignment === null || ! $shiftAssignment->is_working_day) {
            throw new DomainRuleException('勤務予定日ではないため有給を申請できません。');
        }

        $alreadyRequested = PaidLeaveRequest::query()
            ->where('user_id', $command->userId)
            ->whereDate('target_date', $command->targetDate)
            ->whereIn('status', [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED])
            ->exists();

        if ($alreadyRequested) {
            throw new DomainRuleException('この日は既に有給を申請済みです。');
        }

        $requestedDays = $this->resolveRequestedDays($command, $shiftAssignment);

        $remainingDays = (float) PaidLeaveGrant::query()
            ->where('user_id', $command->userId)
            ->whereDate('expires_on', '>=', $command->targetDate)
            ->sum('remaining_days');

        if ($remainingDays < $requestedDays) {
            throw new DomainRuleException('有給残数が不足しています。');
        }

        $request = PaidLeaveRequest::query()->create([
            'user_id' => $command->userId,
            'approver_user_id' => $command->approverUserId,
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'leave_type' => $command->leaveType,
            'target_date' => $command->targetDate,
            'hours' => $command->hours,
            'requested_days' => $requestedDays,
            'reason' => $command->reason,
            'submitted_at' => Carbon::now(),
        ]);

        $this->eventStore->append(
            aggregateType: 'paid_leave_request',
            aggregateId: (string) $request->id,
            event: new PaidLeaveRequested(
                paidLeaveRequestId: $request->id,
                userId: $command->userId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
            ),
        );

        SendTeamsNotificationJob::dispatch(
            title: '有給申請の承認依頼',
            summary: "{$command->targetDate} の有給申請が提出されました。",
            detailUrl: null,
        );

        return $request;
    }

    private function resolveRequestedDays(RequestPaidLeave $command, EmployeeShiftAssignment $shiftAssignment): float
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return 1.0;
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return 0.5;
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間休の場合は取得時間を指定してください。');
            }

            $prescribedDailyMinutes = $shiftAssignment->workStyle?->prescribed_daily_minutes ?? 480;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間休として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
