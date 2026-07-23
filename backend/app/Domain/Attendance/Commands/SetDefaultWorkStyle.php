<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class SetDefaultWorkStyle implements Command
{
    public function __construct(
        public readonly int $workStyleId,
        public readonly string $changedByUserId,
    ) {}
}
