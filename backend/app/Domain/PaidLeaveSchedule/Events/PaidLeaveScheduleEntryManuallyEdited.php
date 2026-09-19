<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleEntryManuallyEdited extends ShouldBeStored
{
    /**
     * @param  array{scheduledOn?: string, category?: string, candidateGrantDays?: float}  $changes
     */
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly array $changes,
        public readonly string $reason,
        public readonly string $operatorUserId,
    ) {}
}
