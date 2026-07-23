<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserTerminationDateSet extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $terminationDate,
        public readonly string $changedByUserId,
    ) {}
}
