<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RoleFeaturesChanged extends ShouldBeStored
{
    public function __construct(public readonly int $roleId, public readonly array $featureIds, public readonly string $actorUserId) {}
}
