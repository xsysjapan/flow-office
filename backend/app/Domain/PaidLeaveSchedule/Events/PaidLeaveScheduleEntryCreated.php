<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleEntryCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly string $scheduledOn,
        public readonly string $category,
        public readonly float $candidateGrantDays,
        public readonly bool $isDeterminate = true,
    ) {}
}
