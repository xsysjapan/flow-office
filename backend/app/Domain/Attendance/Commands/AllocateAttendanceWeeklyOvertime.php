<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class AllocateAttendanceWeeklyOvertime implements Command
{
    public function __construct(
        public readonly string $attendanceDayId,
        public readonly string $weekStartDate,
        public readonly int $prescribedMinutes,
        public readonly int $nonPrescribedMinutes,
        public readonly int $lateNightPrescribedMinutes,
        public readonly int $lateNightNonPrescribedMinutes,
        public readonly string $allocatedByUserId,
    ) {}
}
