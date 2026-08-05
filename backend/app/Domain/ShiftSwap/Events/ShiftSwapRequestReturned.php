<?php

namespace App\Domain\ShiftSwap\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ShiftSwapRequestReturned extends ShouldBeStored
{
    public function __construct(
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
