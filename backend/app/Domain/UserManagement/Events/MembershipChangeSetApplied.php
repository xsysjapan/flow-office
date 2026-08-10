<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MembershipChangeSetApplied extends ShouldBeStored
{
    public function __construct(public readonly string $userId, public readonly array $items, public readonly string $actorUserId) {}
}
