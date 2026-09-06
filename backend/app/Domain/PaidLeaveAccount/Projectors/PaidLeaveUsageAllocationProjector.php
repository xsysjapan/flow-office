<?php

namespace App\Domain\PaidLeaveAccount\Projectors;

use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantAmountChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantDateChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantExpiryChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantWarningRaised;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocationReleased;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveUsage;
use App\Models\PaidLeaveUsageAllocation;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * PaidLeaveAccountAggregate(AggregateId = userId)のイベントから、既存
 * `paid_leave_grants`/`paid_leave_usages`テーブルの新ドメイン用行と、新設
 * `paid_leave_usage_allocations`(Usage×GrantのAllocationのSource of Truth)を
 * 作成・更新する。
 *
 * `paid_leave_grants.allocated_days`/`remaining_days`は、この集約に記録された
 * `paid_leave_account.usage_allocated`/`usage_allocation_released`イベントの合計を
 * `updateOrCreate`で都度反映する非正規化キャッシュであり、Source of Truthは
 * `paid_leave_usage_allocations`(docs/changesets/20260906-paid-leave-domain-redesign/
 * spec.md 論点7)。すべての更新はイベントのプロパティのみから計算する冪等な加減算・上書きで
 * あり、`event-sourcing:replay`によるProjection再生成にそのまま対応する。
 *
 * 旧ドメイン(`App\Domain\PaidLeave\Projectors\PaidLeaveGrantProjector`/
 * `PaidLeaveUsageProjector`)とは別のイベント名前空間(`paid_leave_account.*`)にのみ
 * 反応するため、旧ドメインの行・列(`is_confirmed`等)には触れない。
 */
class PaidLeaveUsageAllocationProjector extends Projector
{
    public function onPaidLeaveGrantCreated(PaidLeaveGrantCreated $event): void
    {
        PaidLeaveGrant::query()->updateOrCreate(
            ['id' => $event->grantId],
            [
                'user_id' => $event->aggregateRootUuid(),
                'granted_on' => $event->grantedOn,
                'expires_on' => $event->expiresOn,
                'granted_days' => $event->grantedDays,
                'allocated_days' => 0,
                'used_days' => 0,
                'remaining_days' => $event->grantedDays,
                'grant_reason' => $event->grantReason,
                'status' => 'active',
            ],
        );
    }

    public function onPaidLeaveGrantAmountChanged(PaidLeaveGrantAmountChanged $event): void
    {
        $grant = PaidLeaveGrant::query()->find($event->grantId);
        if ($grant === null) {
            return;
        }

        $grant->update([
            'granted_days' => $event->newGrantedDays,
            'remaining_days' => $event->newGrantedDays - (float) $grant->allocated_days,
        ]);
    }

    public function onPaidLeaveGrantDateChanged(PaidLeaveGrantDateChanged $event): void
    {
        PaidLeaveGrant::query()->whereKey($event->grantId)->update(['granted_on' => $event->newGrantedOn]);
    }

    public function onPaidLeaveGrantExpiryChanged(PaidLeaveGrantExpiryChanged $event): void
    {
        PaidLeaveGrant::query()->whereKey($event->grantId)->update(['expires_on' => $event->newExpiresOn]);
    }

    public function onPaidLeaveGrantRevoked(PaidLeaveGrantRevoked $event): void
    {
        PaidLeaveGrant::query()->whereKey($event->grantId)->update([
            'status' => 'revoked',
            'revoked_at' => $event->createdAt(),
            'revoked_by_user_id' => $event->revokedByUserId,
            'revoke_reason' => $event->reason,
        ]);
    }

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        // paid_leave_usages.paid_leave_request_idは外部キー制約付きのため、参照先の
        // paid_leave_requests行を必ず先に作る(同一Projector内で順序を保証する。
        // 別クラスに分けるとProjectorの実行順序に依存してFK違反を起こしうるため、
        // あえて1つのProjectorにまとめている)。
        $this->createPaidLeaveRequestIfNeeded($event);

        PaidLeaveUsage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            [
                'user_id' => $event->aggregateRootUuid(),
                'attendance_day_id' => $event->attendanceDayId,
                'paid_leave_grant_id' => null,
                'paid_leave_request_id' => $event->paidLeaveRequestId,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->hours !== null ? (int) round($event->hours * 60) : null,
                'usage_type' => $event->usageType,
                'is_confirmed' => false,
                'confirmed' => false,
                'cancelled' => false,
            ],
        );
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        PaidLeaveUsage::query()->where('usage_id', $event->usageId)->update([
            'confirmed' => true,
            'is_confirmed' => true,
        ]);

        $this->updatePaidLeaveRequestStatus($event->usageId, PaidLeaveRequestStatus::APPROVED, 'approved_at', $event->createdAt());
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        PaidLeaveUsage::query()->where('usage_id', $event->usageId)->update(['cancelled' => true]);

        $this->updatePaidLeaveRequestStatus($event->usageId, PaidLeaveRequestStatus::CANCELLED, 'cancelled_at', $event->createdAt());
    }

    /**
     * 差戻し(returned)は新ドメインのUsageを一切変更しない方針(cutover前の挙動を保つ。
     * `App\Domain\PaidLeave\Handlers\ReturnPaidLeaveRequestHandler`クラスdoc参照)のため、
     * Workflow側の`WorkflowRequestReturned`(既存のWorkflowドメインイベント、regenerable)を
     * 直接購読して`paid_leave_requests.status`を切り替える。
     */
    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST) {
            return;
        }

        PaidLeaveRequest::query()->whereKey($workflowRequest->subject_id)->update([
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    /**
     * `paid_leave_requests`(有給固有の申請パラメータ。docs/changesets/20260906-
     * paid-leave-domain-redesign/spec.md 論点10)を`PaidLeaveUsageDesignated`から作成する。
     * 旧`App\Domain\PaidLeave\Projectors\PaidLeaveRequestProjector`(Phase 5 cutoverで削除)の
     * 役割の置き換え。
     */
    private function createPaidLeaveRequestIfNeeded(PaidLeaveUsageDesignated $event): void
    {
        if ($event->paidLeaveRequestId === null) {
            return;
        }

        PaidLeaveRequest::query()->updateOrCreate(
            ['id' => $event->paidLeaveRequestId],
            [
                'user_id' => $event->aggregateRootUuid(),
                'approver_user_id' => $event->approverUserId,
                'status' => PaidLeaveRequestStatus::SUBMITTED,
                'leave_type' => $event->usageType,
                'target_date' => $event->usedOn,
                'hours' => $event->hours,
                'requested_days' => $event->usedDays,
                'reason' => $event->reason,
                'request_group_id' => $event->requestGroupId,
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    /**
     * `PaidLeaveUsageConfirmed`/`Cancelled`は`usageId`のみを運ぶため、対応する
     * `paid_leave_requests.id`は`paid_leave_usages.paid_leave_request_id`
     * (Designated時点で本Projectorが設定済み)から逆引きする。
     */
    private function updatePaidLeaveRequestStatus(string $usageId, string $status, string $timestampColumn, \DateTimeInterface $occurredAt): void
    {
        $requestId = PaidLeaveUsage::query()->where('usage_id', $usageId)->value('paid_leave_request_id');

        if ($requestId === null) {
            return;
        }

        PaidLeaveRequest::query()->whereKey($requestId)->update([
            'status' => $status,
            $timestampColumn => $occurredAt,
        ]);
    }

    /**
     * UC-P005/UC-P006(消滅警告・年5日取得義務警告)。旧`PaidLeaveGrantAggregate::raiseWarning`
     * 廃止に伴い、同じ`paid_leave_grants.expiry_warned_at`/`five_day_obligation_warned_at`列を
     * このProjectorから書き込む(Warn*Handlerがこのイベントを発行する。
     * `App\Domain\PaidLeave\Handlers\WarnExpiringPaidLeaveHandler`/`WarnFiveDayObligationHandler`参照)。
     */
    public function onPaidLeaveGrantWarningRaised(PaidLeaveGrantWarningRaised $event): void
    {
        $grant = PaidLeaveGrant::query()->find($event->grantId);
        if ($grant === null) {
            return;
        }

        $column = $event->warningType === 'expiry' ? 'expiry_warned_at' : 'five_day_obligation_warned_at';

        $grant->update([$column => $event->createdAt()]);
    }

    public function onPaidLeaveUsageAllocated(PaidLeaveUsageAllocated $event): void
    {
        $allocation = PaidLeaveUsageAllocation::query()->firstOrNew([
            'usage_id' => $event->usageId,
            'grant_id' => $event->grantId,
        ]);
        $allocation->allocated_days = (float) ($allocation->allocated_days ?? 0) + $event->allocatedDays;
        $allocation->save();

        $this->recalculateGrantCache($event->grantId);
        $this->syncUsageGrantReference($event->usageId);
    }

    public function onPaidLeaveUsageAllocationReleased(PaidLeaveUsageAllocationReleased $event): void
    {
        $allocation = PaidLeaveUsageAllocation::query()
            ->where('usage_id', $event->usageId)
            ->where('grant_id', $event->grantId)
            ->first();

        if ($allocation !== null) {
            $remaining = (float) $allocation->allocated_days - $event->releasedDays;

            if ($remaining <= 0) {
                $allocation->delete();
            } else {
                $allocation->update(['allocated_days' => $remaining]);
            }
        }

        $this->recalculateGrantCache($event->grantId);
        $this->syncUsageGrantReference($event->usageId);
    }

    private function recalculateGrantCache(string $grantId): void
    {
        $grant = PaidLeaveGrant::query()->find($grantId);
        if ($grant === null) {
            return;
        }

        $allocatedTotal = (float) PaidLeaveUsageAllocation::query()
            ->where('grant_id', $grantId)
            ->sum('allocated_days');

        $grant->update([
            'allocated_days' => $allocatedTotal,
            // 旧ドメインのused_days/remaining_daysと同じ意味(=消化済み扱いの合計)を保つため、
            // used_daysもallocated_daysと同じ値にしておく(表示用の非正規化キャッシュ。
            // Source of Truthはpaid_leave_usage_allocations)。
            'used_days' => $allocatedTotal,
            'remaining_days' => (float) $grant->granted_days - $allocatedTotal,
        ]);
    }

    /**
     * 表示の便宜上、Allocation先が単一Grantのみの場合はpaid_leave_usages.paid_leave_grant_id
     * にそのGrantを反映する(複数Grantにまたがる場合はnullのままとし、内訳は
     * paid_leave_usage_allocationsを参照させる)。
     */
    private function syncUsageGrantReference(string $usageId): void
    {
        $usage = PaidLeaveUsage::query()->where('usage_id', $usageId)->first();
        if ($usage === null) {
            return;
        }

        $grantIds = PaidLeaveUsageAllocation::query()->where('usage_id', $usageId)->pluck('grant_id');

        $usage->update(['paid_leave_grant_id' => $grantIds->count() === 1 ? $grantIds->first() : null]);
    }
}
