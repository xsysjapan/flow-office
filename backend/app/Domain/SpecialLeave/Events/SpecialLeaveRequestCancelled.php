<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly int $cancelledByUserId,
    ) {}
}
