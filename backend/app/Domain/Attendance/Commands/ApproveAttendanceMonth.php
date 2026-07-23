<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A009: 承認者が月次勤怠を承認する。
 */
class ApproveAttendanceMonth implements Command
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly string $approvedByUserId,
    ) {}
}
