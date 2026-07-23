<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly string $specialLeaveRequestId,
        public readonly int $cancelledByUserId,
    ) {}
}
