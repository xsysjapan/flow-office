<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserFieldAuthorityChanged extends ShouldBeStored
{
    public function __construct(public readonly string $fieldKey, public readonly string $authorityType, public readonly ?string $provider, public readonly string $actorUserId) {}
}
