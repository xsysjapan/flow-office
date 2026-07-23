<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Jobs\SendNotificationJob;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 特別休暇を申請する。有給休暇(RequestPaidLeaveHandler)と同じ考え方だが、
 * 残高・消化は特別休暇種別(special_leave_type_id)ごとにスコープする。
 * 有給とはビジネスロジックを分けて実装し、法定の要件を持つ有給側のルールには一切影響しない。
 *
 * @implements CommandHandler<RequestSpecialLeave>
 */
class RequestSpecialLeaveHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof RequestSpecialLeave);

        $specialLeaveType = SpecialLeaveType::query()->findOrFail($command->specialLeaveTypeId);
        if (! $specialLeaveType->is_active) {
            throw new DomainRuleException('無効な特別休暇種別です。');
        }

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->with('workStyle')
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        if ($shiftAssignment === null || ! $shiftAssignment->is_working_day) {
            throw new DomainRuleException('勤務予定日ではないため特別休暇を申請できません。');
        }

        if ($this->alreadyHasLeaveOnDate($command->userId, $command->targetDate)) {
            throw new DomainRuleException('この日は既に有給または特別休暇を申請済みです。');
        }

        $requestedDays = $this->resolveRequestedDays($command, $shiftAssignment);

        $remainingDays = (float) SpecialLeaveGrant::query()
            ->availableOn($command->targetDate)
            ->where('user_id', $command->userId)
            ->where('special_leave_type_id', $command->specialLeaveTypeId)
            ->sum('remaining_days');

        if ($remainingDays < $requestedDays) {
            throw new DomainRuleException('特別休暇の残数が不足しています。');
        }

        $requestId = (string) Str::uuid();

        SpecialLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
            )
            ->persist();

        $request = SpecialLeaveRequest::query()->findOrFail($requestId);

        $approver = User::find($command->approverUserId);
        if ($approver !== null) {
            SendNotificationJob::enqueue(
                recipient: $approver,
                title: '特別休暇申請の承認依頼',
                summary: "{$command->targetDate} の{$specialLeaveType->name}の申請が提出されました。",
                detailUrl: null,
            );
        }

        return $request;
    }

    /**
     * 同じ日にactive(提出中・承認済み)な有給または特別休暇の申請が既にあるか。
     * attendance_days.work_typeは1日1件しか値を持てないため、どちらの休暇であっても
     * 二重申請を防ぐ必要がある。
     */
    private function alreadyHasLeaveOnDate(string $userId, string $targetDate): bool
    {
        $activeStatuses = [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED];

        $hasPaidLeave = PaidLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasPaidLeave) {
            return true;
        }

        return SpecialLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();
    }

    private function resolveRequestedDays(RequestSpecialLeave $command, EmployeeShiftAssignment $shiftAssignment): float
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return 1.0;
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return 0.5;
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間単位の場合は取得時間を指定してください。');
            }

            $prescribedDailyMinutes = $shiftAssignment->workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間単位として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
