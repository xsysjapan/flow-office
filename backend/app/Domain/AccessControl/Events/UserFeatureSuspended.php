<?php

namespace App\Domain\AccessControl\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserFeatureSuspended extends ShouldBeStored
{
    public function __construct(public readonly string $suspensionId, public readonly string $userId, public readonly int $featureId, public readonly string $reason, public readonly ?string $startsAt, public readonly ?string $endsAt, public readonly string $actorUserId) {}
}
