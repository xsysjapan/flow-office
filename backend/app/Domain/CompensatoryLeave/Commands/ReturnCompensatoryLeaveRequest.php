<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnCompensatoryLeaveRequest implements Command
{
    public function __construct(
        public readonly string $compensatoryLeaveRequestId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
