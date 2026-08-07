<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveUsageReversed;
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

    /**
     * 承認済みの特別休暇申請が取り消された際、対応するusage行を削除する
     * (special_leave_usagesは「現時点で有効な消化」の一覧であり、履歴はstored_eventsに残る)。
     */
    public function onSpecialLeaveUsageReversed(SpecialLeaveUsageReversed $event): void
    {
        SpecialLeaveUsage::query()
            ->where('special_leave_grant_id', $event->aggregateRootUuid())
            ->where('special_leave_request_id', $event->specialLeaveRequestId)
            ->delete();
    }
}
