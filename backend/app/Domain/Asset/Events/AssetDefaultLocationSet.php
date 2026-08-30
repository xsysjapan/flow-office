<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.default_location_set(貸出備品のみ)。 */
class AssetDefaultLocationSet extends ShouldBeStored
{
    public function __construct(
        public readonly string $locationText,
        public readonly string $setByUserId,
    ) {}
}
