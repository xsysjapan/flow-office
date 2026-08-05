<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.grant_removed
 *
 * 元になった勤怠実績が休日出勤でなくなった(通常の労働日になった・実働時間が0になった)、
 * または日次勤怠自体が削除されたことで、ドラフト状態(status=draft)のGrantを取り消す。
 * 既にconfirmed(月次提出済み)のGrantはこのイベントの対象にしない
 * (SyncCompensatoryLeaveGrantHandler参照)。
 */
class CompensatoryLeaveGrantRemoved extends ShouldBeStored
{
    public function __construct(
        public readonly string $reason,
    ) {}
}
