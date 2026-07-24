<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A008: 月次勤怠を提出する。
 */
class SubmitAttendanceMonth implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $approverUserId,
    ) {}
}
