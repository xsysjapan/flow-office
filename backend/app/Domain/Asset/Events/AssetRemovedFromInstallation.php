<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.removed_from_installation(撤去→保管、設置備品のみ)。 */
class AssetRemovedFromInstallation extends ShouldBeStored
{
    public function __construct(
        public readonly string $removedByUserId,
        public readonly string $removedAt,
    ) {}
}
