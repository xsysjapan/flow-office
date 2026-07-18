<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D001/UC-D003: 共有端末または個人端末を登録する(docs/23-usecases-devices.md)。
 */
class RegisterDevice implements Command
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
