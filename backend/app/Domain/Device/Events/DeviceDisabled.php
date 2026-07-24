<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceDisabled extends ShouldBeStored
{
    public function __construct(
        public readonly string $disabledByUserId,
        public readonly string $disabledAt,
    ) {}
}
