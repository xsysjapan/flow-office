<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RoleCreated extends ShouldBeStored
{
    public function __construct(public readonly int $roleId, public readonly string $code, public readonly string $name, public readonly ?string $description, public readonly string $actorUserId) {}
}
