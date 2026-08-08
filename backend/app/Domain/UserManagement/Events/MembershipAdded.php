<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MembershipAdded extends ShouldBeStored
{
    public function __construct(public readonly string $userId, public readonly string $groupId, public readonly string $membershipKind, public readonly bool $isPrimary, public readonly string $actorUserId) {}
}
