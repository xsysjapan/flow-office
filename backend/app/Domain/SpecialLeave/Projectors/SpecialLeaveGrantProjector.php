<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveGrantRevoked;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageReversed;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Models\SpecialLeaveGrant;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * special_leave.*(付与系)イベントから special_leave_grants を作成・更新する。
 * used_days/remaining_daysは、この集約に記録された全special_leave.usedイベントの
 * usedDays合計からspecial_leave.usage_reversedイベントの合計を差し引いた値を都度再計算する
 * (Projectorの再適用・複数回実行に対して冪等にするため。他Projectorの副作用
 * (special_leave_usages行の有無)には依存しない)。
 */
class SpecialLeaveGrantProjector extends Projector
{
    public function onSpecialLeaveGranted(SpecialLeaveGranted $event): void
    {
        SpecialLeaveGrant::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'special_leave_type_id' => $event->specialLeaveTypeId,
                'granted_on' => $event->grantedOn,
                'expires_on' => $event->expiresOn,
                'granted_days' => $event->grantedDays,
                'used_days' => 0,
                'remaining_days' => $event->grantedDays,
                'grant_reason' => $event->grantReason,
            ],
        );
    }

    public function onSpecialLeaveGrantRevoked(SpecialLeaveGrantRevoked $event): void
    {
        SpecialLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => 'revoked',
            'revoked_at' => $event->createdAt(),
            'revoked_by_user_id' => $event->revokedByUserId,
            'revoke_reason' => $event->reason,
        ]);
    }

    public function onSpecialLeaveUsed(SpecialLeaveUsed $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    public function onSpecialLeaveUsageReversed(SpecialLeaveUsageReversed $event): void
    {
        $this->recalculate($event->aggregateRootUuid());
    }

    private function recalculate(string $grantId): void
    {
        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);

        $usedDays = $this->totalUsedDays($grantId) - $this->totalReversedDays($grantId);

        $grant->update([
            'used_days' => $usedDays,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
        ]);
    }

    private function totalUsedDays(string $grantId): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'special_leave.used')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties['usedDays'] ?? 0));
    }

    private function totalReversedDays(string $grantId): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'special_leave.usage_reversed')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties['usedDays'] ?? 0));
    }
}
