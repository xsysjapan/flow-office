<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Models\SpecialLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * special_leave.usedイベントから special_leave_usages を作成する。PaidLeaveUsageProjector
 * と同じ理由でこの行自体は集約ルートではないため、主キーはDB採番のままでよい。
 * 冪等性はstored_events.id(stored_event_id)のユニーク制約で担保する。
 */
class SpecialLeaveUsageProjector extends Projector
{
    public function onSpecialLeaveUsed(SpecialLeaveUsed $event): void
    {
        SpecialLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'special_leave_grant_id' => $event->aggregateRootUuid(),
                'special_leave_request_id' => $event->specialLeaveRequestId,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
            ],
        );
    }
}
