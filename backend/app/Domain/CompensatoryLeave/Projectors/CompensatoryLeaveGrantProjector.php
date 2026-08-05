<?php

namespace App\Domain\CompensatoryLeave\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantConfirmed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantRemoved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantSynced;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsed;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * compensatory_leave.*(付与系)イベントから compensatory_leave_grants /
 * compensatory_leave_usages を作成・更新する(SpecialLeaveGrantProjector・
 * SpecialLeaveUsageProjectorと同じ理由。代休は付与系イベントの種類が多いため1ファイルに
 * まとめる)。
 */
class CompensatoryLeaveGrantProjector extends Projector
{
    public function onCompensatoryLeaveGrantSynced(CompensatoryLeaveGrantSynced $event): void
    {
        // 同一勤怠日の実績が再編集されるたびに繰り返し記録されるため、その都度draftとして
        // 上書きする(この時点で既に使用されていることはない。draft状態のGrantのみが
        // syncの対象になるため。SyncCompensatoryLeaveGrantHandler参照)。
        CompensatoryLeaveGrant::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'work_date' => $event->workDate,
                'granted_days' => $event->grantedDays,
                'granted_minutes' => $event->grantedMinutes,
                'used_days' => 0,
                'used_minutes' => $event->grantedMinutes !== null ? 0 : null,
                'remaining_days' => $event->grantedDays,
                'remaining_minutes' => $event->grantedMinutes,
                'status' => CompensatoryLeaveGrantStatus::DRAFT,
                'confirmed_at' => null,
                'expires_on' => null,
            ],
        );
    }

    public function onCompensatoryLeaveGrantRemoved(CompensatoryLeaveGrantRemoved $event): void
    {
        // 月次未提出(draft)のGrantのみが対象(SyncCompensatoryLeaveGrantHandler参照)。
        // 休日出勤でなくなった実績から自動導出されたGrantであり、実績として残す意味が無いため
        // 行ごと削除する。
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    public function onCompensatoryLeaveGrantConfirmed(CompensatoryLeaveGrantConfirmed $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveGrantStatus::CONFIRMED,
            'confirmed_at' => $event->confirmedAt,
            'expires_on' => $event->expiresOn,
        ]);
    }

    public function onCompensatoryLeaveGrantCancelled(CompensatoryLeaveGrantCancelled $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveGrantStatus::CANCELLED,
            'remaining_days' => 0,
            'remaining_minutes' => 0,
        ]);
    }

    public function onCompensatoryLeaveUsed(CompensatoryLeaveUsed $event): void
    {
        $grantId = $event->aggregateRootUuid();
        $grant = CompensatoryLeaveGrant::query()->findOrFail($grantId);

        CompensatoryLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'compensatory_leave_grant_id' => $grantId,
                'compensatory_leave_request_id' => $event->compensatoryLeaveRequestId,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
            ],
        );

        $usedDays = $this->totalUsed($grantId, 'usedDays');
        $usedMinutes = $grant->granted_minutes !== null ? (int) $this->totalUsed($grantId, 'usedMinutes') : null;

        $grant->update([
            'used_days' => $usedDays,
            'used_minutes' => $usedMinutes,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
            'remaining_minutes' => $grant->granted_minutes !== null ? (int) $grant->granted_minutes - $usedMinutes : null,
        ]);
    }

    private function totalUsed(string $grantId, string $property): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'compensatory_leave.used')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties[$property] ?? 0));
    }
}
