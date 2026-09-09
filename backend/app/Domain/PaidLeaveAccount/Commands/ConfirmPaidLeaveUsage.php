<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ConfirmPaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageId,
        public readonly ?string $confirmedByUserId,
    ) {}
}
