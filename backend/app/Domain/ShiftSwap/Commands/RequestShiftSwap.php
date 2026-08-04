<?php

namespace App\Domain\ShiftSwap\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RequestShiftSwap implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $substituteDate,
        public readonly ?string $approverUserId,
        public readonly ?string $reason,
        public readonly ?string $workflowRequestId = null,
        public readonly ?string $requestId = null,
    ) {}
}
