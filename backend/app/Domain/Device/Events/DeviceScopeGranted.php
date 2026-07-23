<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceScopeGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $scope,
        public readonly int $grantedByUserId,
    ) {}
}
