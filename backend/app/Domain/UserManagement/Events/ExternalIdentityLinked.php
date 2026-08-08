<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ExternalIdentityLinked extends ShouldBeStored
{
    public function __construct(public readonly string $userId, public readonly string $provider, public readonly ?string $externalTenantId, public readonly string $externalSubjectId, public readonly ?string $externalCode, public readonly ?string $email, public readonly string $actorUserId) {}
}
