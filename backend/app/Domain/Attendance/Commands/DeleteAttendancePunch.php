<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A014: 打刻ログを削除する。行は物理削除せず「削除済み」として残す
 * (打刻ログは追記のみで、削除も操作の履歴として参照できるようにする)。
 */
class DeleteAttendancePunch implements Command
{
    public function __construct(
        public readonly int $attendancePunchId,
        public readonly string $reason,
        public readonly int $deletedByUserId,
    ) {}
}
