<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ExternalIdentityUnlinked extends ShouldBeStored
{
    public function __construct(public readonly string $userId, public readonly int $identityId, public readonly string $provider, public readonly string $externalSubjectId, public readonly string $actorUserId) {}
}
