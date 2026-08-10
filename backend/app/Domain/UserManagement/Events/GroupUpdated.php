<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupUpdated extends ShouldBeStored
{
    public function __construct(public readonly string $name, public readonly string $code, public readonly ?string $description, public readonly ?string $parentGroupId, public readonly string $status, public readonly string $actorUserId) {}
}
