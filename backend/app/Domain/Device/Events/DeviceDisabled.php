<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceDisabled implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $disabledByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.disabled';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'disabled_by_user_id' => $this->disabledByUserId,
        ];
    }
}
