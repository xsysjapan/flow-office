<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class UserCreatedManually extends ShouldBeStored
{
    public function __construct(
        public readonly array $attributes,
        public readonly string $createdByUserId,
    ) {}
}
