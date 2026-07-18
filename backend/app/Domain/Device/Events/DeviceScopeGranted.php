<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceScopeGranted implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $scope,
        public readonly int $grantedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.scope_granted';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'scope' => $this->scope,
            'granted_by_user_id' => $this->grantedByUserId,
        ];
    }
}
