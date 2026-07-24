<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P004: 有給を承認する。
 */
class ApprovePaidLeaveRequest implements Command
{
    public function __construct(
        public readonly string $paidLeaveRequestId,
        public readonly string $approvedByUserId,
    ) {}
}
