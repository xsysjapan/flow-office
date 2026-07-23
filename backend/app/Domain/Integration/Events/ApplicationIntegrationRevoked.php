<?php

namespace App\Domain\Integration\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ApplicationIntegrationRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly int $revokedByUserId,
    ) {}
}
