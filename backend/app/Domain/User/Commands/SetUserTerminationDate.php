<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class SetUserTerminationDate implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $terminationDate,
        public readonly int $changedByUserId,
    ) {}
}