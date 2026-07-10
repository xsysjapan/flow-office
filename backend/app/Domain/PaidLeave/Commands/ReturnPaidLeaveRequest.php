<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnPaidLeaveRequest implements Command
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}
}
