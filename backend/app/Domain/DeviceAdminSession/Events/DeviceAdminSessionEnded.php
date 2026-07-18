<?php

namespace App\Domain\DeviceAdminSession\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceAdminSessionEnded implements DomainEvent
{
    public function __construct(
        public readonly int $deviceAdminSessionId,
        public readonly int $deviceId,
    ) {}

    public function eventType(): string
    {
        return 'device_admin_session.ended';
    }

    public function payload(): array
    {
        return [
            'device_admin_session_id' => $this->deviceAdminSessionId,
            'device_id' => $this->deviceId,
        ];
    }
}
