<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceRoleAssigned implements DomainEvent
{
    /**
     * @param  array<int, string>  $roleTypes
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly array $roleTypes,
        public readonly int $updatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.role_assigned';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'role_types' => $this->roleTypes,
            'updated_by_user_id' => $this->updatedByUserId,
        ];
    }
}
