<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelCompensatoryLeaveRequest implements Command
{
    public function __construct(
        public readonly string $compensatoryLeaveRequestId,
        public readonly string $cancelledByUserId,
        public readonly bool $isAdminAction = false,
    ) {}
}
