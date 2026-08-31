<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.management_type_changed */
class AssetManagementTypeChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $managementType,
        public readonly string $changedByUserId,
    ) {}
}
