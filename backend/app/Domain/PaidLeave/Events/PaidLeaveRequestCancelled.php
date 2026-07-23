<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly int $cancelledByUserId,
    ) {}
}
