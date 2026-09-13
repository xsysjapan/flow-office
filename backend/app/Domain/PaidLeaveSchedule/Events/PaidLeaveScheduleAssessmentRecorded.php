<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleAssessmentRecorded extends ShouldBeStored
{
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly string $assessmentId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $denominatorDays,
        public readonly int $attendanceDays,
        public readonly int $excludedDays,
        public readonly ?float $attendanceRate,
        public readonly string $policyVersion,
        public readonly string $automaticResult,
    ) {}
}
