<?php

namespace App\Domain\ExternalIntegration\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ExternalIntegrationConnectionCreated extends ShouldBeStored
{
    public function __construct(public readonly array $after, public readonly string $actorUserId) {}
}
