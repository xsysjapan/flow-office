<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.relocated(設置備品のみ。既に設置済みの状態での設置場所変更)。 */
class AssetRelocated extends ShouldBeStored
{
    public function __construct(
        public readonly string $locationText,
        public readonly string $relocatedByUserId,
        public readonly string $relocatedAt,
    ) {}
}
