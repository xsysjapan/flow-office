<?php

namespace App\Domain\DeviceAdminSession\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceAdminSessionStarted implements DomainEvent
{
    public function __construct(
        public readonly int $deviceAdminSessionId,
        public readonly int $deviceId,
        public readonly int $adminUserId,
        public readonly string $source,
    ) {}

    public function eventType(): string
    {
        return 'device_admin_session.started';
    }

    public function payload(): array
    {
        return [
            'device_admin_session_id' => $this->deviceAdminSessionId,
            'device_id' => $this->deviceId,
            'admin_user_id' => $this->adminUserId,
            'source' => $this->source,
        ];
    }
}
