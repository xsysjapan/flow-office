<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MembershipChangeSetFailed extends ShouldBeStored
{
    public function __construct(public readonly string $reason, public readonly string $actorUserId) {}
}
