<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateRole implements Command
{
    public function __construct(public readonly int $roleId, public readonly string $name, public readonly ?string $description, public readonly string $status, public readonly string $actorUserId) {}
}
