<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Jobs\SendNotificationJob;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * UC-P003: 有給を申請する。
 *
 * @implements CommandHandler<RequestPaidLeave>
 */
class RequestPaidLeaveHandler implements CommandHandler
{
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

        $alreadyHasSpecialLeave = SpecialLeaveRequest::query()
            ->where('user_id', $command->userId)
            ->whereDate('target_date', $command->targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();

        if ($alreadyHasSpecialLeave) {
            throw new DomainRuleException('この日は既に特別休暇を申請済みです。');
        }

        $requestedDays = $this->resolveRequestedDays($command, $shiftAssignment);

        $remainingDays = (float) PaidLeaveGrant::query()
            ->where('user_id', $command->userId)
            ->whereDate('expires_on', '>=', $command->targetDate)
            ->sum('remaining_days');

        if ($remainingDays < $requestedDays) {
            throw new DomainRuleException('有給残数が不足しています。');
        }

        $requestId = (string) Str::uuid();

        PaidLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
            )
            ->persist();

        $request = PaidLeaveRequest::query()->findOrFail($requestId);

        $approver = User::find($command->approverUserId);
        if ($approver !== null) {
            SendNotificationJob::enqueue(
                recipient: $approver,
                title: '有給申請の承認依頼',
                summary: "{$command->targetDate} の有給申請が提出されました。",
                detailUrl: null,
            );
        }

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

            // work_style_idは必須カラムのため、勤務予定日(is_working_day=trueを既に確認済み)であれば
            // workStyleは必ず存在する。マスタ値をそのまま使い、ハードコードしたフォールバックは持たない。
            $prescribedDailyMinutes = $shiftAssignment->workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間休として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
