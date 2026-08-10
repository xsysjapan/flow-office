<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserProfileUpdated extends ShouldBeStored
{
    public function __construct(public readonly array $before, public readonly array $after, public readonly string $changedByUserId) {}
}
