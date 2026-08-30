<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.lending_method_changed */
class AssetLendingMethodChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $lendingMethod,
        public readonly string $changedByUserId,
    ) {}
}
