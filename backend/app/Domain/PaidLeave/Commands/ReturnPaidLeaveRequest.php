<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnPaidLeaveRequest implements Command
{
    public function __construct(
        public readonly string $paidLeaveRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}
}
