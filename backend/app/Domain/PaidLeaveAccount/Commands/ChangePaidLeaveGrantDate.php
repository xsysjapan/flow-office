<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangePaidLeaveGrantDate implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $newGrantedOn,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
