<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompensatoryLeaveRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
    ) {}
}
