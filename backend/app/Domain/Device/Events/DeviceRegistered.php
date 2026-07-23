<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * device.registered。DeviceProjectorが集約UUID(aggregateRootUuid())をキーに
 * devices / device_rolesの行を新規作成する。
 */
class DeviceRegistered extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $roleTypes
     * @param  array<int, string>|null  $allowedPunchTypes
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly ?int $ownerUserId,
        public readonly string $name,
        public readonly string $deviceType,
        public readonly array $roleTypes,
        public readonly ?string $siteId,
        public readonly ?string $locationName,
        public readonly ?string $defaultWorkLocationType,
        public readonly ?string $timezone,
        public readonly ?array $allowedPunchTypes,
        public readonly bool $allowOffline,
        public readonly bool $requireLocation,
        public readonly bool $autoDetectPunchType,
        public readonly int $registeredByUserId,
    ) {}
}
