<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RoleUpdated extends ShouldBeStored
{
    public function __construct(public readonly int $roleId, public readonly string $name, public readonly ?string $description, public readonly string $status, public readonly string $actorUserId) {}
}
