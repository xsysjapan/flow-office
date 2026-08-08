<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ApplyExternalHrImport implements Command
{
    public function __construct(public readonly string $importId, public readonly array $rows, public readonly string $actorUserId) {}
}
