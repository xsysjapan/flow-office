<?php

namespace App\Domain\PaidLeave\Projectors;

use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeave\Events\PaidLeaveUsageReversed;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Models\PaidLeaveGrant;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * paid_leave.*(付与系)イベントから paid_leave_grants を作成・更新する。
 * used_days/remaining_daysは、この集約に記録された全paid_leave.usedイベントの
 * usedDays合計からpaid_leave.usage_reversedイベントの合計を差し引いた値を都度再計算する
 * (Projectorの再適用・複数回実行に対して冪等にするため。他Projectorの副作用
 * (paid_leave_usages行の有無)には依存しない)。
 */
class PaidLeaveGrantProjector extends Projector
{
    public function onPaidLeaveGranted(PaidLeaveGranted $event): void
    {
        PaidLeaveGrant::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'granted_on' => $event->grantedOn,
                'expires_on' => $event->expiresOn,
                'granted_days' => $event->grantedDays,
                'used_days' => 0,
                'remaining_days' => $event->grantedDays,
                'grant_reason' => $event->grantReason,
            ],
        );
    }

    public function onPaidLeaveGrantRevoked(PaidLeaveGrantRevoked $event): void
    {
        PaidLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'revoked',
            'revoked_at' => $event->createdAt(),
            'revoked_by_user_id' => $event->revokedByUserId,
            'revoke_reason' => $event->reason,
        ]);
    }

    public function onPaidLeaveUsed(PaidLeaveUsed $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onPaidLeaveUsageReversed(PaidLeaveUsageReversed $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    private function recalculate(string $grantId): void
    {
        $grant = PaidLeaveGrant::query()->findOrFail($grantId);

        $usedDays = $this->totalUsedDays($grantId) - $this->totalReversedDays($grantId);

        $grant->update([
            'used_days' => $usedDays,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
        ]);
    }

    public function onPaidLeaveWarningRaised(PaidLeaveWarningRaised $event): void
    {
        $grant = PaidLeaveGrant::query()->find($event->aggregateRootUuid());
        if ($grant === null) {
            return;
        }

        $column = $event->warningType === 'expiry' ? 'expiry_warned_at' : 'five_day_obligation_warned_at';

        $grant->update([$column => $event->createdAt()]);
    }

    private function totalUsedDays(string $grantId): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'paid_leave.used')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties['usedDays'] ?? 0));
    }

    private function totalReversedDays(string $grantId): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'paid_leave.usage_reversed')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties['usedDays'] ?? 0));
    }
}
