<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ApproveCompensatoryLeaveGrantCancellation implements Command
{
    public function __construct(
        public readonly int $cancellationId,
        public readonly string $approvedByUserId,
    ) {}
}
