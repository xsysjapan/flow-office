<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_punch.deleted
 *
 * UC-A014: 打刻ログを削除する。行は物理削除せず「削除済み」として残す(打刻ログは
 * 追記のみで、削除自体も操作の履歴として理由・実行者付きで参照できるようにする)。
 */
class AttendancePunchDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $reason,
        public readonly string $deletedByUserId,
    ) {}
}
