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
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\PaidLeaveUsageAllocation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * PaidLeaveAccountAggregate(AggregateId = `streamIdFor(userId)`。社員IDは各イベントの`userId`)のイベントから、既存
 * `paid_leave_grants`/`paid_leave_usages`テーブルの新ドメイン用行と、新設
 * `paid_leave_usage_allocations`(Usage×GrantのAllocationのSource of Truth)を
 * 作成・更新する。
 *
 * `paid_leave_grants.allocated_days`/`remaining_days`は、この集約に記録された
 * `paid_leave_account.usage_allocated`/`usage_allocation_released`イベントの合計を
 * `updateOrCreate`で都度反映する非正規化キャッシュであり、Source of Truthは
 * `paid_leave_usage_allocations`(docs/changesets/20260906-paid-leave-domain-redesign/
 * spec.md 論点7)。更新はイベントのプロパティのみから計算する。ただし`onPaidLeaveUsageAllocated`は
 * 既存行へ`allocated_days`を足し込むため、`event-sourcing:replay`で再生成するときは、書き込み先
 * テーブル(`paid_leave_grants`/`paid_leave_usages`/`paid_leave_usage_allocations`)を空にしてから
 * replayする(空にせずにreplayすると充当が二重になる)。
 * 取り消した付与(`status=revoked`)の`remaining_days`は0(使える残日数の意味に揃える)。
 *
 * 旧ドメイン(`App\Domain\PaidLeave\Projectors\PaidLeaveGrantProjector`/
 * `PaidLeaveUsageProjector`)とは別のイベント名前空間(`paid_leave_account.*`)にのみ
 * 反応するため、旧ドメインの行・列(`is_confirmed`等)には触れない。
 *
 * `paid_leave_requests`(有給の申請状態)はこのProjectorでは更新しない。休暇申請文脈の
 * `App\Domain\PaidLeaveRequest\Projectors\PaidLeaveRequestProjector`が作る(論点4)。
 */
class PaidLeaveUsageAllocationProjector extends Projector
{
    public function onPaidLeaveGrantCreated(PaidLeaveGrantCreated $event): void
    {
        PaidLeaveGrant::query()->updateOrCreate(
            ['id' => $event->grantId],
            [
                'user_id' => $event->userId,
                'granted_on' => $event->grantedOn,
                'expires_on' => $event->expiresOn,
                'granted_days' => $event->grantedDays,
                'allocated_days' => 0,
                'used_days' => 0,
                'remaining_days' => $event->grantedDays,
                'grant_reason' => $event->grantReason,
                'source' => $event->source,
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
            'remaining_days' => $this->remainingDays($grant->status, (float) $event->newGrantedDays, (float) $grant->allocated_days),
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
            'remaining_days' => 0,
        ]);
    }

    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        PaidLeaveUsage::query()->updateOrCreate(
            ['usage_id' => $event->usageId],
            [
                'user_id' => $event->userId,
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
    }

    public function onPaidLeaveUsageCancelled(PaidLeaveUsageCancelled $event): void
    {
        PaidLeaveUsage::query()->where('usage_id', $event->usageId)->update(['cancelled' => true]);
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
            'remaining_days' => $this->remainingDays($grant->status, (float) $grant->granted_days, $allocatedTotal),
        ]);
    }

    /**
     * 付与の残日数(使える残日数)。取り消した付与は0、それ以外は付与日数から充当の合計を引いた値。
     */
    private function remainingDays(string $status, float $grantedDays, float $allocatedTotal): float
    {
        if ($status === 'revoked') {
            return 0.0;
        }

        return $grantedDays - $allocatedTotal;
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
