<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleEntryGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly string $grantId,
        public readonly string $operatorUserId,
    ) {}
}
