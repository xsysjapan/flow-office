<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelPaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageId,
        public readonly ?string $cancelledByUserId,
        public readonly ?string $reason,
    ) {}
}
