<?php

namespace App\Domain\ExternalIntegration\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ExternalIntegrationConnectionDeleted extends ShouldBeStored
{
    public function __construct(public readonly array $before, public readonly string $actorUserId) {}
}
