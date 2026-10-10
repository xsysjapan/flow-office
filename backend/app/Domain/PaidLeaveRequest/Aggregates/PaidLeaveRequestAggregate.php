<?php

namespace App\Domain\PaidLeaveRequest\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleMigrated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 有給申請の集約(集約ID=申請ID)。申請の状態(申請中・差戻し・承認済み・取消)だけを持つ。
 * 残数・消化記録(usage)は有給口座(PaidLeaveAccount)の集約が持ち、本集約は関与しない。
 *
 * 状態遷移: none → submitted(申請中) → approved / returned / cancelled、
 * returned → submitted(再提出) / cancelled、approved → cancelled。
 * migrated は本変更前の申請の現在状態を none から引き継ぐ(none のときのみ)。
 *
 * 冪等性(viaReactorの既に目的の状態なら何もしない)は呼び出し側(Handler)が
 * isXxx()で判断する。本集約は不正な遷移を DomainRuleException で拒否する。
 * Eloquent・DB・Facadeには依存しない。
 */
class PaidLeaveRequestAggregate extends AggregateRoot
{
    private const STATUS_NONE = 'none';
    private const STATUS_SUBMITTED = 'submitted';
    private const STATUS_RETURNED = 'returned';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_CANCELLED = 'cancelled';

    private const MIGRATABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_RETURNED,
        self::STATUS_APPROVED,
        self::STATUS_CANCELLED,
    ];

    private string $status = self::STATUS_NONE;

    /** cutover前の承認済み申請(消化記録なしで引き継がれた申請)。取消できない。 */
    private bool $cutoverWithoutUsage = false;

    public function request(
        string $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?string $approverUserId,
        ?string $reason,
        ?string $requestGroupId = null,
        ?string $workflowRequestId = null,
    ): self {
        if ($this->status !== self::STATUS_NONE) {
            throw new DomainRuleException('既に申請されている有給申請です。');
        }

        $this->recordThat(new PaidLeaveRequestLifecycleRequested(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
            workflowRequestId: $workflowRequestId,
        ));

        return $this;
    }

    public function share(string $workflowRequestId): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない有給申請は提出できません。');

        $this->recordThat(new PaidLeaveRequestLifecycleShared(workflowRequestId: $workflowRequestId));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない有給申請は承認できません。');

        $this->recordThat(new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function return(?string $returnedByUserId, ?string $comment): self
    {
        $this->assertStatus([self::STATUS_SUBMITTED], '申請中でない有給申請は差し戻せません。');

        $this->recordThat(new PaidLeaveRequestLifecycleReturned(
            returnedByUserId: $returnedByUserId,
            comment: $comment,
        ));

        return $this;
    }

    public function resubmit(?string $resubmittedByUserId): self
    {
        $this->assertStatus([self::STATUS_RETURNED], '差戻し中でない有給申請は再提出できません。');

        $this->recordThat(new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $resubmittedByUserId));

        return $this;
    }

    public function cancel(?string $cancelledByUserId, ?string $reason): self
    {
        $this->assertStatus(
            [self::STATUS_SUBMITTED, self::STATUS_RETURNED, self::STATUS_APPROVED],
            '取消できない状態の有給申請です。',
        );

        if ($this->status === self::STATUS_APPROVED && $this->cutoverWithoutUsage) {
            throw new DomainRuleException(
                '移行前の申請のため取消できません。付与日数の調整で対応してください。',
            );
        }

        $this->recordThat(new PaidLeaveRequestLifecycleCancelled(
            cancelledByUserId: $cancelledByUserId,
            reason: $reason,
        ));

        return $this;
    }

    /**
     * 本変更前に申請された有給申請の現在状態を引き継ぐ(paid_leave_request.migrated)。
     * none のときのみ実行できる(二重引き継ぎ禁止)。
     */
    public function migrate(
        string $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?string $approverUserId,
        ?string $reason,
        ?string $requestGroupId,
        ?string $workflowRequestId,
        string $status,
        ?string $usageId,
        bool $hasUsage,
    ): self {
        if ($this->status !== self::STATUS_NONE) {
            throw new DomainRuleException('既に引き継ぎ済み、または申請済みの有給申請です。');
        }
        if (! in_array($status, self::MIGRATABLE_STATUSES, true)) {
            throw new DomainRuleException('引き継ぎ不可の状態です: '.$status);
        }

        $this->recordThat(new PaidLeaveRequestLifecycleMigrated(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
            workflowRequestId: $workflowRequestId,
            status: $status,
            usageId: $usageId,
            hasUsage: $hasUsage,
        ));

        return $this;
    }

    /** 現在の状態。申請前は'none'。 */
    public function status(): string
    {
        return $this->status;
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

    protected function applyPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    protected function applyPaidLeaveRequestLifecycleShared(PaidLeaveRequestLifecycleShared $event): void
    {
        // 状態は変えない(申請中のまま)。
    }

    protected function applyPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->status = self::STATUS_APPROVED;
    }

    protected function applyPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->status = self::STATUS_RETURNED;
    }

    protected function applyPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    protected function applyPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    protected function applyPaidLeaveRequestLifecycleMigrated(PaidLeaveRequestLifecycleMigrated $event): void
    {
        $this->status = $event->status;
        $this->cutoverWithoutUsage = $event->status === self::STATUS_APPROVED && ! $event->hasUsage;
    }
}
