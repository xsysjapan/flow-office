<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class FeatureAssignedToGroup extends ShouldBeStored
{
    public function __construct(public readonly string $groupId, public readonly int $featureId, public readonly string $actorUserId) {}
}
