<?php

namespace App\Domain\PaidLeave\Projectors;

use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Models\PaidLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * paid_leave.usedイベントから paid_leave_usages を作成する。この行自体はpaid_leave_grant
 * 集約のイベントから作られる派生データであり、自身は集約ルートではない(このイベント成立後に
 * 「このusage行」を対象にした後続コマンドは存在しない)ため、主キーはDB採番のままでよい。
 * 冪等性はstored_events.id(stored_event_id)のユニーク制約で担保する。
 */
class PaidLeaveUsageProjector extends Projector
{
    public function onPaidLeaveUsed(PaidLeaveUsed $event): void
    {
        PaidLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'paid_leave_grant_id' => $event->aggregateRootUuid(),
                'paid_leave_request_id' => $event->paidLeaveRequestId,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
            ],
        );
    }
}
