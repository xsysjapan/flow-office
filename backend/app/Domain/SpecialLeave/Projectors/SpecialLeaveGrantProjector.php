<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Models\SpecialLeaveGrant;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * special_leave.*(付与系)イベントから special_leave_grants を作成・更新する。
 * used_days/remaining_daysは、この集約に記録された全special_leave.usedイベントの
 * usedDays合計から都度再計算する(PaidLeaveGrantProjectorと同じ理由)。
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

    public function onSpecialLeaveUsed(SpecialLeaveUsed $event): void
    {
        $grantId = $event->aggregateRootUuid();
        $grant = SpecialLeaveGrant::query()->findOrFail($grantId);

        $usedDays = $this->totalUsedDays($grantId);

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
}
