<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RoleAssignmentUpdated extends ShouldBeStored
{
    public function __construct(public readonly string $scopeType, public readonly ?string $scopeGroupId, public readonly bool $includeDescendants, public readonly ?string $startsAt, public readonly ?string $endsAt, public readonly string $actorUserId) {}
}
