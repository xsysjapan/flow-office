<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RequestSpecialLeave implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly int $approverUserId,
        public readonly ?string $reason,
    ) {}
}
