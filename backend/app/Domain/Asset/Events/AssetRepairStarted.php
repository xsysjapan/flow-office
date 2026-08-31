<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.repair_started(貸出品・設置品共通)。 */
class AssetRepairStarted extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $note,
        public readonly string $startedByUserId,
    ) {}
}
