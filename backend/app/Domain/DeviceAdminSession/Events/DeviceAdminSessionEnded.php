<?php

namespace App\Domain\DeviceAdminSession\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceAdminSessionEnded extends ShouldBeStored
{
    public function __construct(
        public readonly string $endedAt,
    ) {}
}
