<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RolePermissionsChanged extends ShouldBeStored
{
    public function __construct(public readonly int $roleId, public readonly array $permissionIds, public readonly string $actorUserId) {}
}
