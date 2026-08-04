<?php

namespace App\Domain\ShiftSwap\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ShiftSwapRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
    ) {}
}
