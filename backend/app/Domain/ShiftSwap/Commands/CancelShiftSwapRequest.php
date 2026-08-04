<?php

namespace App\Domain\ShiftSwap\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelShiftSwapRequest implements Command
{
    public function __construct(
        public readonly string $shiftSwapRequestId,
        public readonly string $cancelledByUserId,
    ) {}
}
