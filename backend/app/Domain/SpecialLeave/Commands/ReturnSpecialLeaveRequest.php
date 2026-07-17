<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly int $specialLeaveRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}
}
