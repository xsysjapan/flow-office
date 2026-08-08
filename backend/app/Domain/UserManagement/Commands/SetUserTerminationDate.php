<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class SetUserTerminationDate implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $terminationDate,
        public readonly string $changedByUserId,
    ) {}
}
