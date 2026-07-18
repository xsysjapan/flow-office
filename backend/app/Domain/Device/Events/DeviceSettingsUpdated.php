<?php

namespace App\Domain\Device\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class DeviceSettingsUpdated implements DomainEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $name,
        public readonly ?string $siteId,
        public readonly ?string $locationName,
        public readonly ?string $defaultWorkLocationType,
        public readonly ?string $timezone,
        public readonly ?array $allowedPunchTypes,
        public readonly bool $allowOffline,
        public readonly bool $requireLocation,
        public readonly bool $autoDetectPunchType,
        public readonly int $updatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'device.settings_updated';
    }

    public function payload(): array
    {
        return [
            'device_id' => $this->deviceId,
            'name' => $this->name,
            'site_id' => $this->siteId,
            'location_name' => $this->locationName,
            'default_work_location_type' => $this->defaultWorkLocationType,
            'timezone' => $this->timezone,
            'allowed_punch_types' => $this->allowedPunchTypes,
            'allow_offline' => $this->allowOffline,
            'require_location' => $this->requireLocation,
            'auto_detect_punch_type' => $this->autoDetectPunchType,
            'updated_by_user_id' => $this->updatedByUserId,
        ];
    }
}
