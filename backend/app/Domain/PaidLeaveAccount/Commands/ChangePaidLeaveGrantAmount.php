<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangePaidLeaveGrantAmount implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly float $newGrantedDays,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
