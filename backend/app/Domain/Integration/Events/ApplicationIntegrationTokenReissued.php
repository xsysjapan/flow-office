<?php

namespace App\Domain\Integration\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ApplicationIntegrationTokenReissued extends ShouldBeStored
{
    public function __construct(
        public readonly int $personalAccessTokenId,
        public readonly int $reissuedByUserId,
    ) {}
}
