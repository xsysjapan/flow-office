<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P004: 有給を承認する。
 */
class ApprovePaidLeaveRequest implements Command
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $approvedByUserId,
    ) {}
}
