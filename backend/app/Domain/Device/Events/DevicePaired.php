<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DevicePaired implements DomainEvent
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly array $abilities,
    ) {}

    public function eventType(): string
    {
        return 'device.paired';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'abilities' => $this->abilities,
        ];
    }
}
