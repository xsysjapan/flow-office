<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly string $specialLeaveRequestId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
