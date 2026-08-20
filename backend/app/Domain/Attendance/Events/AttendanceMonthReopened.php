<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance.month_reopened
 *
 * 救済コマンド: 管理者が締め済みの月次勤怠の締めを取り消す(closed→approved)。
 * 承認イベント自体は書き換えず、新しい状態遷移として記録する。
 */
class AttendanceMonthReopened extends ShouldBeStored
{
    public function __construct(
        public readonly string $reopenedByUserId,
        public readonly string $reason,
    ) {}
}
