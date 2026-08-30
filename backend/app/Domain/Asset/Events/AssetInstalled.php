<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.installed(設置備品のみ)。 */
class AssetInstalled extends ShouldBeStored
{
    public function __construct(
        public readonly string $locationText,
        public readonly string $installedByUserId,
        public readonly string $installedAt,
    ) {}
}
