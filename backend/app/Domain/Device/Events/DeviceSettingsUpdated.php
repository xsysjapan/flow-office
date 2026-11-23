<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceSettingsUpdated extends ShouldBeStored
{
    /**
     * @param  array<int, string>|null  $allowedPunchTypes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $siteId,
        public readonly ?string $locationName,
        public readonly ?string $defaultWorkLocationType,
        public readonly ?string $timezone,
        public readonly ?array $allowedPunchTypes,
        public readonly bool $allowsOffline,
        public readonly bool $requiresLocation,
        public readonly bool $punchTypeAutoDetectionEnabled,
        public readonly string $updatedByUserId,
    ) {}
}
