<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RequestCompensatoryLeaveGrantCancellation implements Command
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $requestedByUserId,
        public readonly ?string $reason,
    ) {}
}
