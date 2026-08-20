<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AttendanceWeeklyOvertimeAllocated extends ShouldBeStored
{
    public function __construct(
        public readonly string $weekStartDate,
        public readonly int $prescribedMinutes,
        public readonly int $nonPrescribedMinutes,
        public readonly int $lateNightPrescribedMinutes,
        public readonly int $lateNightNonPrescribedMinutes,
        public readonly string $allocatedByUserId,
    ) {}
}
