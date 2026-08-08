<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RoleAssignmentRemoved extends ShouldBeStored
{
    public function __construct(public readonly string $actorUserId) {}
}
