<?php

namespace App\Domain\AuthenticationKey\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AuthenticationKeyDisabled extends ShouldBeStored
{
    public function __construct(
        public readonly string $disabledByUserId,
        public readonly string $disabledAt,
    ) {}
}
