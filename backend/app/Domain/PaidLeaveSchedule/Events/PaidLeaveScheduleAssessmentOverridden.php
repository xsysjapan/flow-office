<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleAssessmentOverridden extends ShouldBeStored
{
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly string $assessmentId,
        public readonly string $finalResult,
        public readonly string $reason,
        public readonly string $operatorUserId,
    ) {}
}
