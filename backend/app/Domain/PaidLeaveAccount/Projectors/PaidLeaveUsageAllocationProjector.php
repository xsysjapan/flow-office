<?php

namespace App\Domain\PaidLeaveAccount\Projectors;

use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantAmountChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantDateChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantExpiryChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocationReleased;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveAccountUsage;
use App\Models\PaidLeaveUsageAllocation;
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
        PaidLeaveAccountUsage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            [
                'user_id' => $event->aggregateRootUuid(),
                'attendance_day_id' => $event->attendanceDayId,
                'paid_leave_grant_id' => null,
                'paid_leave_request_id' => null,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'usage_type' => null,
                'confirmed' => false,
                'cancelled' => false,
            ],
        );
    }

    public function onPaidLeaveUsageConfirmed(PaidLeaveUsageConfirmed $event): void
    {
        PaidLeaveAccountUsage::query()->where('usage_id', $event->usageId)->update(['confirmed' => true]);
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        PaidLeaveAccountUsage::query()->where('usage_id', $event->usageId)->update(['cancelled' => true]);
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
        $usage = PaidLeaveAccountUsage::query()->where('usage_id', $usageId)->first();
        if ($usage === null) {
            return;
        }

        $grantIds = PaidLeaveUsageAllocation::query()->where('usage_id', $usageId)->pluck('grant_id');

        $usage->update(['paid_leave_grant_id' => $grantIds->count() === 1 ? $grantIds->first() : null]);
    }
}
