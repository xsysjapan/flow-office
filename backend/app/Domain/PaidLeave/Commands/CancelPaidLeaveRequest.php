<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelPaidLeaveRequest implements Command
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $cancelledByUserId,
    ) {}
}
