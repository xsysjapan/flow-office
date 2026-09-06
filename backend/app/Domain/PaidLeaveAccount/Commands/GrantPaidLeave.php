<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class GrantPaidLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
        public readonly string $source = 'manual',
    ) {}
}
