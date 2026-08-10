<?php

namespace App\Domain\SystemSettings\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SystemSettingsUpdated extends ShouldBeStored
{
    public function __construct(public readonly array $before, public readonly array $after, public readonly string $actorUserId) {}
}
