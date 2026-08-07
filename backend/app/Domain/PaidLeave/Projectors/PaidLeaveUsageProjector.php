<?php

namespace App\Domain\PaidLeave\Projectors;

use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeave\Events\PaidLeaveUsageReversed;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Models\PaidLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * paid_leave_usagesを作成・更新する。この行自体はpaid_leave_request集約・paid_leave_grant
 * 集約のイベントから作られる派生データであり、自身は集約ルートではない(このイベント成立後に
 * 「このusage行」を対象にした後続コマンドは存在しない)ため、主キーはDB採番のままでよい。
 * 冪等性はstored_events.id(stored_event_id)のユニーク制約で担保する。
 *
 * 行のライフサイクル: 申請時点でpaid_leave.usage_designatedによりgrant_id未確定・
 * is_confirmed=falseの行が1件作られる(勤怠側はこの行の存在だけで「休暇が設定されているか」
 * を判定でき、paid_leave_requestsを参照しに行く必要が無い)。承認時、最初のpaid_leave.used
 * イベントがこの行を確定させ(grant_id設定・is_confirmed=true)、1申請が複数grantにまたがる
 * 場合は2件目以降を新規の確定済み行として追加する。
 */
class PaidLeaveUsageProjector extends Projector
{
    public function onPaidLeaveUsageDesignated(PaidLeaveUsageDesignated $event): void
    {
        PaidLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'paid_leave_grant_id' => null,
                'paid_leave_request_id' => $event->aggregateRootUuid(),
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
                'is_confirmed' => false,
            ],
        );
    }

    public function onPaidLeaveUsed(PaidLeaveUsed $event): void
    {
        $pending = PaidLeaveUsage::query()
            ->where('paid_leave_request_id', $event->paidLeaveRequestId)
            ->whereNull('paid_leave_grant_id')
            ->first();

        if ($pending !== null) {
            $pending->update([
                'stored_event_id' => $event->storedEventId(),
                'paid_leave_grant_id' => $event->aggregateRootUuid(),
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'is_confirmed' => true,
            ]);

            return;
        }

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
                'is_confirmed' => true,
            ],
        );
    }

    /**
     * 承認済みの有給申請が取り消された際、対応するusage行を削除する
     * (paid_leave_usagesは「現時点で有効な消化」の一覧であり、履歴はstored_eventsに残る)。
     */
    public function onPaidLeaveUsageReversed(PaidLeaveUsageReversed $event): void
    {
        PaidLeaveUsage::query()
            ->where('paid_leave_grant_id', $event->aggregateRootUuid())
            ->where('paid_leave_request_id', $event->paidLeaveRequestId)
            ->delete();
    }

    /**
     * 未承認(submitted)のまま取消された場合、grant消化はまだ発生していないため
     * (paid_leave.usage_reversedは発行されない)、承認前の設定行(is_confirmed=false)を
     * ここで削除する。承認済みの取消はonPaidLeaveUsageReversedが確定済み行を削除するため、
     * ここではis_confirmed=falseの行のみを対象にする(重複削除を避ける)。
     */
    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        PaidLeaveUsage::query()
            ->where('paid_leave_request_id', $event->aggregateRootUuid())
            ->where('is_confirmed', false)
            ->delete();
    }
}
