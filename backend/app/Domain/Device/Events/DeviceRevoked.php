<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
        public readonly string $revokedAt,
    ) {}
}
