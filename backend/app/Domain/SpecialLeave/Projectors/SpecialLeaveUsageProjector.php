<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageDesignated;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageReversed;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use App\Models\SpecialLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * special_leave_usagesを作成・更新する。この行自体はspecial_leave_request集約・special_leave_grant
 * 集約のイベントから作られる派生データであり、自身は集約ルートではない(このイベント成立後に
 * 「このusage行」を対象にした後続コマンドは存在しない)ため、主キーはDB採番のままでよい。
 * 冪等性はstored_events.id(stored_event_id)のユニーク制約で担保する。
 *
 * 行のライフサイクル: 申請時点でspecial_leave.usage_designatedによりgrant_id未確定・
 * is_confirmed=falseの行が1件作られる(勤怠側はこの行の存在だけで「休暇が設定されているか」
 * を判定でき、special_leave_requestsを参照しに行く必要が無い)。承認時、最初のspecial_leave.used
 * イベントがこの行を確定させ(grant_id設定・is_confirmed=true)、1申請が複数grantにまたがる
 * 場合は2件目以降を新規の確定済み行として追加する(PaidLeaveUsageProjectorと同じ考え方)。
 */
class SpecialLeaveUsageProjector extends Projector
{
    public function onSpecialLeaveUsageDesignated(SpecialLeaveUsageDesignated $event): void
    {
        SpecialLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'special_leave_grant_id' => null,
                'special_leave_request_id' => $event->aggregateRootUuid(),
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
                'is_confirmed' => false,
            ],
        );
    }

    public function onSpecialLeaveUsed(SpecialLeaveUsed $event): void
    {
        $pending = SpecialLeaveUsage::query()
            ->where('special_leave_request_id', $event->specialLeaveRequestId)
            ->whereNull('special_leave_grant_id')
            ->first();

        if ($pending !== null) {
            $pending->update([
                'stored_event_id' => $event->storedEventId(),
                'special_leave_grant_id' => $event->aggregateRootUuid(),
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'is_confirmed' => true,
            ]);

            return;
        }

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
                'is_confirmed' => true,
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

    /**
     * 未承認(submitted)のまま取消された場合、grant消化はまだ発生していないため
     * (special_leave.usage_reversedは発行されない)、承認前の設定行(is_confirmed=false)を
     * ここで削除する。承認済みの取消はonSpecialLeaveUsageReversedが確定済み行を削除するため、
     * ここではis_confirmed=falseの行のみを対象にする(重複削除を避ける)。
     */
    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        SpecialLeaveUsage::query()
            ->where('special_leave_request_id', $event->aggregateRootUuid())
            ->where('is_confirmed', false)
            ->delete();
    }
}
