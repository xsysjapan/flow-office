<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class FeatureRemovedFromGroup extends ShouldBeStored
{
    public function __construct(public readonly string $groupId, public readonly int $featureId, public readonly string $actorUserId) {}
}
