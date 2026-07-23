<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A010: 承認者が月次勤怠を差戻しする。
 */
class ReturnAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $attendanceMonthId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
