<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserFeatureSuspensionRemoved extends ShouldBeStored
{
    public function __construct(public readonly string $suspensionId, public readonly string $userId, public readonly string $actorUserId) {}
}
