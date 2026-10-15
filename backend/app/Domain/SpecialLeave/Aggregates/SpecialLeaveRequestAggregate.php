<?php

namespace App\Domain\SpecialLeave\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 特別休暇申請の集約(集約ID=申請ID)。申請の状態(申請中・差戻し・承認済み・取消)と、申請の内容
 * (申請者・対象日・取得単位・承認者など。遷移イベントに載せて残数・勤怠の連鎖へ渡す)だけを持つ。
 * 残数・消化記録は特別休暇口座(SpecialLeaveAccount)、勤怠の休暇は勤怠の休暇ビューが持ち、本集約は関与しない。
 *
 * 状態遷移: none → submitted(申請中) → approved / returned / cancelled、
 * returned → submitted(再提出) / cancelled、approved → cancelled。
 *
 * 冪等性(viaReactorで既に目的の状態なら何もしない)は呼び出し側(Handler)がisXxx()で判断する。
 * 本集約は不正な遷移を DomainRuleException で拒否する。Eloquent・DB・Facadeには依存しない。
 */
class SpecialLeaveRequestAggregate extends AggregateRoot
{
    private const STATUS_NONE = 'none';

    private const STATUS_SUBMITTED = 'submitted';

    private const STATUS_RETURNED = 'returned';

    private const STATUS_APPROVED = 'approved';

    private const STATUS_CANCELLED = 'cancelled';

    private string $status = self::STATUS_NONE;

    private ?string $userId = null;

    private ?int $specialLeaveTypeId = null;

    private ?string $targetDate = null;

    private ?string $leaveType = null;

    private ?float $hours = null;

    private ?float $requestedDays = null;

    /** 承認者(承認不要時の申請は申請者自身。申請前はnull)。 */
    private ?string $approverUserId = null;

    private ?string $reason = null;

    private ?string $requestGroupId = null;

    private ?string $workflowRequestId = null;

    public function request(
        string $userId,
        int $specialLeaveTypeId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        string $approverUserId,
        ?string $reason,
        ?string $requestGroupId = null,
    ): self {
        if ($this->status !== self::STATUS_NONE) {
            throw new DomainRuleException('既に申請されている特別休暇申請です。');
        }

        $this->recordThat(new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: $specialLeaveTypeId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
        ));

        return $this;
    }

    /** 申請したワークフローを提出済みとして対応付ける(ワークフローの提出はWorkflow側のReactorが行う)。 */
    public function share(string $workflowRequestId): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない特別休暇申請は提出できません。');

        $this->recordThat(new SpecialLeaveRequestShared(workflowRequestId: $workflowRequestId));

        return $this;
    }

    /**
     * 承認する。requiresGrantは種別の「残数を要するか」(承認時の充当に使う。口座側のReactorへ渡す)。
     */
    public function approve(?string $approvedByUserId, bool $requiresGrant): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない特別休暇申請は承認できません。');

        $this->recordThat(new SpecialLeaveRequestApproved(
            approvedByUserId: $approvedByUserId,
            userId: $this->userId,
            specialLeaveTypeId: $this->specialLeaveTypeId,
            targetDate: $this->targetDate,
            leaveType: $this->leaveType,
            hours: $this->hours,
            requestedDays: $this->requestedDays,
            requiresGrant: $requiresGrant,
        ));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない特別休暇申請は差し戻せません。');

        $this->recordThat(new SpecialLeaveRequestReturned(
            returnedByUserId: $returnedByUserId,
            comment: $comment,
            userId: $this->userId,
            specialLeaveTypeId: $this->specialLeaveTypeId,
            targetDate: $this->targetDate,
            leaveType: $this->leaveType,
            hours: $this->hours,
            requestedDays: $this->requestedDays,
        ));

        return $this;
    }

    /**
     * 差戻し後の再提出。申請の内容は変えず申請中に戻す(新しい消化記録は残数側がこのイベントから作る)。
     */
    public function resubmit(?string $resubmittedByUserId): self
    {
        $this->assertStatus([self::STATUS_RETURNED], '差戻し中でない特別休暇申請は再提出できません。');

        $this->recordThat(new SpecialLeaveRequestResubmitted(
            resubmittedByUserId: $resubmittedByUserId,
            userId: $this->userId,
            specialLeaveTypeId: $this->specialLeaveTypeId,
            targetDate: $this->targetDate,
            leaveType: $this->leaveType,
            hours: $this->hours,
            requestedDays: $this->requestedDays,
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
            '取消できない状態の特別休暇申請です。',
        );

        $this->recordThat(new SpecialLeaveRequestCancelled(
            cancelledByUserId: $cancelledByUserId,
            userId: $this->userId,
            specialLeaveTypeId: $this->specialLeaveTypeId,
            targetDate: $this->targetDate,
            leaveType: $this->leaveType,
            hours: $this->hours,
            requestedDays: $this->requestedDays,
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

    public function specialLeaveTypeId(): ?int
    {
        return $this->specialLeaveTypeId;
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

    protected function applySpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
        $this->applyDetails(
            $event->userId,
            $event->specialLeaveTypeId,
            $event->targetDate,
            $event->leaveType,
            $event->hours,
            $event->requestedDays,
            $event->approverUserId,
            $event->reason,
            $event->requestGroupId,
        );
    }

    protected function applySpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $this->workflowRequestId = $event->workflowRequestId;
    }

    protected function applySpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        $this->status = self::STATUS_APPROVED;
    }

    protected function applySpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        $this->status = self::STATUS_RETURNED;
    }

    protected function applySpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    protected function applySpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    private function applyDetails(
        string $userId,
        int $specialLeaveTypeId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?string $approverUserId,
        ?string $reason,
        ?string $requestGroupId,
    ): void {
        $this->userId = $userId;
        $this->specialLeaveTypeId = $specialLeaveTypeId;
        $this->targetDate = $targetDate;
        $this->leaveType = $leaveType;
        $this->hours = $hours;
        $this->requestedDays = $requestedDays;
        $this->approverUserId = $approverUserId;
        $this->reason = $reason;
        $this->requestGroupId = $requestGroupId;
    }
}
