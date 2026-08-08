<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangeRolePermissions implements Command
{
    public function __construct(public readonly int $roleId, public readonly array $permissionIds, public readonly string $actorUserId) {}
}
