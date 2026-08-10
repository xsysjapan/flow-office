<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MembershipChangeSetCancelled extends ShouldBeStored
{
    public function __construct(public readonly string $actorUserId) {}
}
