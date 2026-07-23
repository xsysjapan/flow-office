<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceRoleAssigned extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $roleTypes
     */
    public function __construct(
        public readonly array $roleTypes,
        public readonly int $updatedByUserId,
    ) {}
}
