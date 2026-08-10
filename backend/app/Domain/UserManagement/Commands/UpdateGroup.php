<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateGroup implements Command
{
    public function __construct(public readonly string $groupId, public readonly string $name, public readonly string $code, public readonly ?string $description, public readonly ?string $parentGroupId, public readonly string $status, public readonly string $actorUserId) {}
}
