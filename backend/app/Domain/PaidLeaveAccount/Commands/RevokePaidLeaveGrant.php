<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RevokePaidLeaveGrant implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
