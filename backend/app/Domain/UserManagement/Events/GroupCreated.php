<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupCreated extends ShouldBeStored
{
    public function __construct(public readonly int $groupTypeId, public readonly string $name, public readonly string $code, public readonly ?string $description, public readonly ?string $parentGroupId, public readonly string $actorUserId) {}
}
