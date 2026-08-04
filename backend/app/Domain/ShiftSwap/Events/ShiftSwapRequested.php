<?php

namespace App\Domain\ShiftSwap\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ShiftSwapRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $substituteDate,
        public readonly ?string $approverUserId,
        public readonly ?string $reason,
    ) {}
}
