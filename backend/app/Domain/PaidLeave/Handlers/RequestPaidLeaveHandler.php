<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Services\ScheduledWorkingDayResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveApprovalRequirement;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Services\PaidLeaveRequestApprover;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveType;
use Illuminate\Support\Carbon;

/**
 * UC-P003: 有給を申請する。有給申請の集約(PaidLeaveRequestAggregate)へ申請を記録するだけで、
 * 他の文脈の集約・テーブルは読み書きしない。
 *
 * - 勤務予定日でない日・時間休の時間数と所定労働時間の整合の判定は、勤怠文脈の休暇の反映(ApplyLeaveToAttendanceDay)が行う。
 * - 残数の消化記録(有給口座)・勤怠の休暇・ワークフローの提出は、記録された
 *   `paid_leave_request.requested`/`.shared`をそれぞれの文脈のReactorが受けて行う(原則15)。
 * - viaReactor=true(workflow_request.drafted からのReactor発行)で、同じ申請IDが既に申請済みなら何もしない(冪等)。
 * - 利用者の操作(viaReactor=false)の直接の申請は、承認が必要な設定なら拒否する(承認が必要な申請はワークフローの下書きから行う)。
 *   承認不要の設定なら、申請を記録した後に承認(approvedByUserId=null。PaidLeaveRequestApproverと共通)まで記録する。
 *
 * @implements CommandHandler<RequestPaidLeave>
 */
class RequestPaidLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly ScheduledWorkingDayResolver $scheduledWorkingDayResolver,
        private readonly LeaveApprovalRequirement $approvalRequirement,
        private readonly PaidLeaveRequestApprover $approver,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof RequestPaidLeave);

        $aggregate = PaidLeaveRequestAggregate::retrieve($command->requestId);

        if ($command->viaReactor && $aggregate->status() !== 'none') {
            return PaidLeaveRequest::query()->findOrFail($command->requestId);
        }

        if (! $command->viaReactor) {
            $this->assertDirectRequestAllowed();
        }

        $requestedDays = $this->resolveRequestedDays($command);

        $aggregate->request(
            userId: $command->userId,
            targetDate: $command->targetDate,
            leaveType: $command->leaveType,
            hours: $command->hours,
            requestedDays: $requestedDays,
            approverUserId: $command->approverUserId ?? $command->userId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
            workflowRequestId: $command->workflowRequestId,
        );

        // ワークフローと対応する申請は提出を記録する(ワークフローの提出はWorkflow側のReactorが行う)。
        if ($command->workflowRequestId !== null) {
            $aggregate->share($command->workflowRequestId);
        }

        $aggregate->persist();

        // 承認不要の設定での直接の申請: 集約を取り出し直して承認を記録する(申請と承認の2回のpersist。1つのトランザクション)。
        if (! $command->viaReactor) {
            return $this->approver->approve($command->requestId, null, false);
        }

        return PaidLeaveRequest::query()->findOrFail($command->requestId);
    }

    private function assertDirectRequestAllowed(): void
    {
        $rejection = $this->approvalRequirement->directRequestRejection(LeaveApprovalRequirement::PAID_LEAVE);

        if ($rejection !== null) {
            throw new DomainRuleException($rejection);
        }
    }

    private function resolveRequestedDays(RequestPaidLeave $command): float
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

            // 日数への換算に所定労働時間が要る(換算だけをここで行う)。勤務予定日・時間数の妥当性の判定は
            // 勤怠文脈の休暇の反映(ApplyLeaveToAttendanceDay)が行う。
            $workStyle = $this->scheduledWorkingDayResolver->resolveWorkStyle($command->userId, Carbon::parse($command->targetDate));

            if ($workStyle === null) {
                throw new DomainRuleException('働き方が特定できないため時間休を申請できません。');
            }

            // マスタ値をそのまま使い、ハードコードしたフォールバックは持たない。
            $prescribedDailyMinutes = $workStyle->prescribed_daily_minutes;
            $requestedDays = round(($command->hours * 60) / $prescribedDailyMinutes, 1);

            return $requestedDays;
        }

        throw new DomainRuleException('不正な取得単位です。');
    }
}
