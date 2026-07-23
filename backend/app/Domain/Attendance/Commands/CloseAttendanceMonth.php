<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A011: 管理部が月次勤怠を締める。
 */
class CloseAttendanceMonth implements Command
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly string $closedByUserId,
    ) {}
}
