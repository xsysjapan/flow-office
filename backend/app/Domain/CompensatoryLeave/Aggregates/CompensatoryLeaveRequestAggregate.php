<?php

namespace App\Domain\CompensatoryLeave\Aggregates;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 代休申請の集約(集約ID=申請ID)。申請の状態(申請中・差戻し・承認済み・取消)と、申請の内容
 * (申請者・対象日・取得単位・申請日数・承認者など。遷移イベントに載せて残数・勤怠の連鎖へ渡す)だけを持つ。
 * 残数・消化記録は代休口座(CompensatoryLeaveAccount)、勤怠の休暇は勤怠の休暇ビューが持ち、本集約は関与しない。
 *
 * 状態遷移: none → submitted(申請中) → approved / returned / cancelled、
 * returned → submitted(再提出) / cancelled、approved → cancelled。
 *
 * 冪等性(viaReactorで既に目的の状態なら何もしない)は呼び出し側(Handler)がisXxx()で判断する。
 * 本集約は不正な遷移を DomainRuleException で拒否する。Eloquent・DB・Facadeには依存しない。
 * (SpecialLeaveRequestAggregateと同じ形。)
 */
class CompensatoryLeaveRequestAggregate extends AggregateRoot
{
    private const STATUS_NONE = 'none';

    private const STATUS_SUBMITTED = 'submitted';

    private const STATUS_RETURNED = 'returned';

    private const STATUS_APPROVED = 'approved';

    private const STATUS_CANCELLED = 'cancelled';

    private string $status = self::STATUS_NONE;

    private ?string $userId = null;

    private ?string $targetDate = null;

    private ?string $leaveType = null;

    private ?float $hours = null;

    private ?float $requestedDays = null;

    private ?int $requestedMinutes = null;

    /** 承認者(承認不要時の申請は申請者自身。申請前はnull)。 */
    private ?string $approverUserId = null;

    private ?string $reason = null;

    private ?string $requestGroupId = null;

    private ?string $workflowRequestId = null;

    public function request(
        string $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?int $requestedMinutes,
        string $approverUserId,
        ?string $reason,
        ?string $requestGroupId = null,
    ): self {
        if ($this->status !== self::STATUS_NONE) {
            throw new DomainRuleException('既に申請されている代休申請です。');
        }

        $this->recordThat(new CompensatoryLeaveRequested(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            requestedMinutes: $requestedMinutes,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
        ));

        return $this;
    }

    /** 申請したワークフローを提出済みとして対応付ける(ワークフローの提出はWorkflow側のReactorが行う)。 */
    public function share(string $workflowRequestId): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない代休申請は提出できません。');

        $this->recordThat(new CompensatoryLeaveRequestShared(workflowRequestId: $workflowRequestId));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない代休申請は承認できません。');

        $this->recordThat(new CompensatoryLeaveRequestApproved(
            approvedByUserId: $approvedByUserId,
            userId: $this->userId,
        ));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない代休申請は差し戻せません。');

        $this->recordThat(new CompensatoryLeaveRequestReturned(
            returnedByUserId: $returnedByUserId,
            comment: $comment,
            userId: $this->userId,
        ));

        return $this;
    }

    /**
     * 差戻し後の再提出。申請の内容は変えず申請中に戻す(新しい消化記録は残数側がこのイベントから作る)。
     */
    public function resubmit(?string $resubmittedByUserId): self
    {
        $this->assertStatus([self::STATUS_RETURNED], '差戻し中でない代休申請は再提出できません。');

        $this->recordThat(new CompensatoryLeaveRequestResubmitted(
            resubmittedByUserId: $resubmittedByUserId,
            userId: $this->userId,
            targetDate: $this->targetDate,
            leaveType: $this->leaveType,
            hours: $this->hours,
            requestedDays: $this->requestedDays,
            requestedMinutes: $this->requestedMinutes,
            approverUserId: $this->approverUserId,
            reason: $this->reason,
            requestGroupId: $this->requestGroupId,
        ));

        return $this;
    }

    public function cancel(string $cancelledByUserId, ?string $reason = null): self
    {
        $this->assertStatus(
            [self::STATUS_SUBMITTED, self::STATUS_RETURNED, self::STATUS_APPROVED],
            '取消できない状態の代休申請です。',
        );

        $this->recordThat(new CompensatoryLeaveRequestCancelled(
            cancelledByUserId: $cancelledByUserId,
            userId: $this->userId,
            reason: $reason,
        ));

        return $this;
    }

    /** 現在の状態。申請前は'none'。 */
    public function status(): string
    {
        return $this->status;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function approverUserId(): ?string
    {
        return $this->approverUserId;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** @param string[] $allowed */
    private function assertStatus(array $allowed, string $message): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw new DomainRuleException($message);
        }
    }

    protected function applyCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
        $this->applyDetails(
            $event->userId,
            $event->targetDate,
            $event->leaveType,
            $event->hours,
            $event->requestedDays,
            $event->requestedMinutes,
            $event->approverUserId,
            $event->reason,
            $event->requestGroupId,
        );
    }

    protected function applyCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
    {
        $this->workflowRequestId = $event->workflowRequestId;
    }

    protected function applyCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        $this->status = self::STATUS_APPROVED;
    }

    protected function applyCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        $this->status = self::STATUS_RETURNED;
    }

    protected function applyCompensatoryLeaveRequestResubmitted(CompensatoryLeaveRequestResubmitted $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    protected function applyCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    private function applyDetails(
        string $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?int $requestedMinutes,
        string $approverUserId,
        ?string $reason,
        ?string $requestGroupId,
    ): void {
        $this->userId = $userId;
        $this->targetDate = $targetDate;
        $this->leaveType = $leaveType;
        $this->hours = $hours;
        $this->requestedDays = $requestedDays;
        $this->requestedMinutes = $requestedMinutes;
        $this->approverUserId = $approverUserId;
        $this->reason = $reason;
        $this->requestGroupId = $requestGroupId;
    }
}
