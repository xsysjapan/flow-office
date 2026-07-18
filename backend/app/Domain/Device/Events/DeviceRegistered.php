<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceRegistered implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $ownerType,
        public readonly ?int $ownerUserId,
        public readonly string $name,
        public readonly string $deviceType,
        public readonly int $registeredByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.registered';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'owner_type' => $this->ownerType,
            'owner_user_id' => $this->ownerUserId,
            'name' => $this->name,
            'device_type' => $this->deviceType,
            'registered_by_user_id' => $this->registeredByUserId,
        ];
    }
}
