<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceRevoked implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $revokedByUserId,
        public readonly ?string $reason,
    ) {}

    public function eventType(): string
    {
        return 'device.revoked';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'revoked_by_user_id' => $this->revokedByUserId,
            'reason' => $this->reason,
        ];
    }
}
