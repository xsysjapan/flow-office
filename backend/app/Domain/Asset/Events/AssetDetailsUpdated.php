<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.details_updated */
class AssetDetailsUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $serialNumber,
        public readonly ?string $notes,
        public readonly string $updatedByUserId,
    ) {}
}
