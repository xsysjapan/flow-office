<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceDeleted implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $deletedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.deleted';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'deleted_by_user_id' => $this->deletedByUserId,
        ];
    }
}
