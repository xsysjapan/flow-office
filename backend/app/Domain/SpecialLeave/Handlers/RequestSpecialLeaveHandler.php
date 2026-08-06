<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveType;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
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
    public function __construct(private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver) {}

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

        $targetDate = Carbon::parse($command->targetDate);
        $workStyle = $shiftAssignment?->workStyle;

        if ($shiftAssignment !== null) {
            if (! $shiftAssignment->is_working_day) {
                throw new DomainRuleException('勤務予定日ではないため特別休暇を申請できません。');
            }
        } else {
            // 通常勤務(シフト非対象)は運用上employee_shift_assignmentsが事前展開されないことが
            // 多いため、勤務予定が無い日は「未展開」として扱い、その月に割り当てられた働き方
            // (無ければシステムのデフォルト働き方)から所定労働日かどうかを判定する
            // (ScheduledWorkingDayResolver参照)。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, $targetDate);

            if (! $this->scheduledWorkingDayResolver->isWorkingDay($command->userId, $targetDate)) {
                throw new DomainRuleException('勤務予定日ではないため特別休暇を申請できません。');
            }
        }

        if ($this->alreadyHasLeaveOnDate($command->userId, $command->targetDate)) {
            throw new DomainRuleException('この日は既に有給または特別休暇を申請済みです。');
        }

        $requestedDays = $this->resolveRequestedDays($command, $workStyle);

        // requires_grant=falseの種別(忌引・代休等、会社の制度上あらかじめ残数を付与しない
        // 種別)は残数チェックをスキップする。
        if ($specialLeaveType->requires_grant) {
            $remainingDays = (float) SpecialLeaveGrant::query()
                ->availableOn($command->targetDate)
                ->where('user_id', $command->userId)
                ->where('special_leave_type_id', $command->specialLeaveTypeId)
                ->sum('remaining_days');

            if ($remainingDays < $requestedDays) {
                throw new DomainRuleException('特別休暇の残数が不足しています。');
            }
        }

        $requestId = $command->requestId ?? (string) Str::uuid();

        $aggregate = SpecialLeaveRequestAggregate::retrieve($requestId)
            ->request(
                userId: $command->userId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                targetDate: $command->targetDate,
                leaveType: $command->leaveType,
                hours: $command->hours,
                requestedDays: $requestedDays,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
                requestGroupId: $command->requestGroupId,
            );

        // workflow_requestが指定されている場合、SpecialLeaveRequestSharedイベントを発行して
        // workflow_requestの提出を促す(ReactorからのRequestSpecialLeaveのみこのIDを持つ)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        // 通知はSubmitWorkflowRequestHandlerが一括して送るため、ここでは送らない
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)

        return SpecialLeaveRequest::query()->findOrFail($requestId);
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

    private function resolveRequestedDays(RequestSpecialLeave $command, ?WorkStyle $workStyle): float
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

            if ($workStyle === null) {
                throw new DomainRuleException('働き方が特定できないため時間単位の特別休暇を申請できません。');
            }

            $prescribedDailyMinutes = $workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            if ($requestedDays <= 0 || $requestedDays >= 1) {
                throw new DomainRuleException('時間単位として妥当な取得時間を指定してください。');
            }

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
