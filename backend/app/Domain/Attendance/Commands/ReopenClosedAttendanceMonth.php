<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 救済コマンド: 管理者が締め済みの月次勤怠の締めを取り消す(closed→approved)。
 */
class ReopenClosedAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
        public readonly string $reopenedByUserId,
        public readonly string $reason,
    ) {}
}
