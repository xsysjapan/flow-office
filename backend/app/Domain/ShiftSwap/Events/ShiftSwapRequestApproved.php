<?php

namespace App\Domain\ShiftSwap\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ShiftSwapRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
    ) {}
}
