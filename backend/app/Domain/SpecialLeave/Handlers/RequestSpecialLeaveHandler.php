<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveApprovalRequirement;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\SpecialLeave\Services\SpecialLeaveRequestApprover;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 特別休暇を申請する。特別休暇申請の集約(SpecialLeaveRequestAggregate)へ申請を記録するだけで、
 * 他の文脈の集約・テーブルは読み書きしない(原則15)。
 *
 * - 種別の有効性・時間休の時間数の入力検証は、この文脈のルールとして残す。勤務予定日でない日・時間数と所定労働時間の整合の判定は、勤怠文脈の休暇の反映(ApplyLeaveToAttendanceDay)が行う。
 * - 残数の消化記録(特別休暇口座)・勤怠の休暇(勤怠のReactor)・ワークフローの提出は、記録された
 *   `special_leave.requested`/`.shared`をそれぞれの文脈のReactorが受けて行う。
 * - 同日の休暇の衝突・締め判定は勤怠文脈が休暇の反映時に行う(違反なら連鎖全体が取り消される)。
 * - viaReactor=true(workflow_request.drafted からのReactor発行)で、同じ申請IDが既に申請済みなら何もしない(冪等)。
 * - 利用者の操作(viaReactor=false)の直接の申請は、承認が必要な設定なら拒否する(承認が必要な申請はワークフローの下書きから行う)。
 *   承認不要の設定なら、申請を記録した後に承認(approvedByUserId=null。SpecialLeaveRequestApproverと共通)まで記録する。
 *
 * @implements CommandHandler<RequestSpecialLeave>
 */
class RequestSpecialLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly LeaveApprovalRequirement $approvalRequirement,
        private readonly SpecialLeaveRequestApprover $approver,
    ) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof RequestSpecialLeave);

        $requestId = $command->requestId ?? (string) Str::uuid();
        $aggregate = SpecialLeaveRequestAggregate::retrieve($requestId);

        if ($command->viaReactor && $aggregate->status() !== 'none') {
            return SpecialLeaveRequest::query()->findOrFail($requestId);
        }

        if (! $command->viaReactor) {
            $this->assertDirectRequestAllowed();
        }

        $specialLeaveType = SpecialLeaveType::query()->findOrFail($command->specialLeaveTypeId);
        if (! $specialLeaveType->is_active) {
            throw new DomainRuleException('無効な特別休暇種別です。');
        }

        $requestedDays = $this->resolveRequestedDays($command);

        $aggregate->request(
            userId: $command->userId,
            specialLeaveTypeId: $command->specialLeaveTypeId,
            targetDate: $command->targetDate,
            leaveType: $command->leaveType,
            hours: $command->hours,
            requestedDays: $requestedDays,
            approverUserId: $command->approverUserId ?? $command->userId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
        );

        // ワークフローと対応する申請は提出を記録する(ワークフローの提出はWorkflow側のReactorが行う)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share(workflowRequestId: $command->workflowRequestId);
        }

        $aggregate->persist();

        // 承認不要の設定での直接の申請: 集約を取り出し直して承認を記録する(申請と承認の2回のpersist。1つのトランザクション)。
        if (! $command->viaReactor) {
            return $this->approver->approve($requestId, null, false);
        }

        return SpecialLeaveRequest::query()->findOrFail($requestId);
    }

    private function assertDirectRequestAllowed(): void
    {
        $rejection = $this->approvalRequirement->directRequestRejection(LeaveApprovalRequirement::SPECIAL_LEAVE);

        if ($rejection !== null) {
            throw new DomainRuleException($rejection);
        }
    }

    private function resolveRequestedDays(RequestSpecialLeave $command): float
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

            // 日数への換算に所定労働時間が要る(換算だけをここで行う)。勤務予定日・時間数の妥当性の判定は
            // 勤怠文脈の休暇の反映(ApplyLeaveToAttendanceDay)が行う。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, Carbon::parse($command->targetDate));

            if ($workStyle === null) {
                throw new DomainRuleException('働き方が特定できないため時間単位の特別休暇を申請できません。');
            }

            $prescribedDailyMinutes = $workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
