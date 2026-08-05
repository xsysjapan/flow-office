<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 代休を消化申請する(RequestSpecialLeaveHandlerと同じ考え方)。付与(Grant)は勤怠実績から
 * 自動導出されるため、ここでは既存の確定済みGrantの残数チェックのみを行う。
 *
 * @implements CommandHandler<RequestCompensatoryLeave>
 */
class RequestCompensatoryLeaveHandler implements CommandHandler
{
    public function __construct(private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver) {}

    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof RequestCompensatoryLeave);

        $unit = SystemSetting::current()->compensatory_leave_unit;
        $this->assertLeaveTypeAllowed($unit, $command->leaveType);

        $targetDate = Carbon::parse($command->targetDate);

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->targetDate)
            ->first();

        if ($shiftAssignment !== null) {
            if (! $shiftAssignment->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
            }
        } elseif (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
            throw new DomainRuleException('勤務予定日ではないため代休を申請できません。');
        }

        if ($this->alreadyHasLeaveOnDate($command->userId, $command->targetDate)) {
            throw new DomainRuleException('この日は既に有給・特別休暇・代休を申請済みです。');
        }

        [$requestedDays, $requestedMinutes] = $this->resolveRequestedAmount($command);

        $this->assertSufficientRemaining($command->userId, $command->leaveType, $requestedDays, $requestedMinutes, $command->targetDate);

        $requestId = $command->requestId ?? (string) Str::uuid();

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                requestedMinutes: $requestedMinutes,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
            );

        // workflow_requestが指定されている場合、CompensatoryLeaveRequestSharedイベントを発行して
        // workflow_requestの提出を促す(SpecialLeaveと同じパターン)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        return CompensatoryLeaveRequest::query()->findOrFail($requestId);
    }

    private function assertLeaveTypeAllowed(string $unit, string $leaveType): void
    {
        $allowed = match ($unit) {
            'daily' => [PaidLeaveType::FULL],
            'half_day' => [PaidLeaveType::FULL, PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF],
            'hourly' => [PaidLeaveType::HOURLY],
            default => [],
        };

        if (! in_array($leaveType, $allowed, true)) {
            throw new DomainRuleException('現在の代休取得単位設定では指定の取得単位は使用できません。');
        }
    }

    /**
     * 同じ日にactive(提出中・承認済み)な有給・特別休暇・代休の申請が既にあるか。
     * attendance_days.work_typeは1日1件しか値を持てないため、いずれの休暇であっても
     * 二重申請を防ぐ必要がある。
     */
    private function alreadyHasLeaveOnDate(string $userId, string $targetDate): bool
    {
        $hasPaidLeave = PaidLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED])
            ->exists();

        if ($hasPaidLeave) {
            return true;
        }

        $hasSpecialLeave = SpecialLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED])
            ->exists();

        if ($hasSpecialLeave) {
            return true;
        }

        return CompensatoryLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereDate('target_date', $targetDate)
            ->whereIn('status', [CompensatoryLeaveRequestStatus::SUBMITTED, CompensatoryLeaveRequestStatus::APPROVED])
            ->exists();
    }

    /**
     * @return array{0: float, 1: ?int}
     */
    private function resolveRequestedAmount(RequestCompensatoryLeave $command): array
    {
        if ($command->leaveType === PaidLeaveType::FULL) {
            return [1.0, null];
        }

        if (in_array($command->leaveType, [PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF], true)) {
            return [0.5, null];
        }

        if ($command->leaveType === PaidLeaveType::HOURLY) {
            if ($command->hours === null || $command->hours <= 0) {
                throw new DomainRuleException('時間単位の場合は取得時間を指定してください。');
            }

            return [0.0, (int) round($command->hours * 60)];
        }

        throw new DomainRuleException('不正な取得単位です。');
    }

    private function assertSufficientRemaining(
        string $userId,
        string $leaveType,
        float $requestedDays,
        ?int $requestedMinutes,
        string $targetDate,
    ): void {
        $query = CompensatoryLeaveGrant::query()
            ->availableOn($targetDate)
            ->where('user_id', $userId)
            ->where('status', CompensatoryLeaveGrantStatus::CONFIRMED);

        if ($leaveType === PaidLeaveType::HOURLY) {
            $remainingMinutes = (int) (clone $query)->where('remaining_minutes', '>', 0)->sum('remaining_minutes');

            if ($remainingMinutes < $requestedMinutes) {
                throw new DomainRuleException('代休の残り時間が不足しています。');
            }

            return;
        }

        $remainingDays = (float) (clone $query)->where('remaining_days', '>', 0)->sum('remaining_days');

        if ($remainingDays < $requestedDays) {
            throw new DomainRuleException('代休の残数が不足しています。');
        }
    }
}
