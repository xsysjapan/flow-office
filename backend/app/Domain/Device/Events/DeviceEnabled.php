<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceEnabled extends ShouldBeStored
{
    public function __construct(
        public readonly string $enabledByUserId,
        public readonly string $enabledAt,
    ) {}
}
