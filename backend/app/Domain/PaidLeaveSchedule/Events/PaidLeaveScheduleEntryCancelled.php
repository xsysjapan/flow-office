<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveScheduleEntryCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly ?string $reason,
        public readonly ?string $byUserId,
    ) {}
}
