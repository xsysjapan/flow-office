<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangePaidLeaveGrantExpiry implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $newExpiresOn,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
