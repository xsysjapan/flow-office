<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly int $approvedByUserId,
    ) {}
}
