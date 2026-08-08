<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MembershipChangeSetCreated extends ShouldBeStored
{
    public function __construct(public readonly string $userId, public readonly string $effectiveAt, public readonly string $sourceType, public readonly array $items, public readonly ?string $note, public readonly string $actorUserId) {}
}
