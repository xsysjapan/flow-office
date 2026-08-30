<?php

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** asset.returned */
class AssetReturned extends ShouldBeStored
{
    public function __construct(
        public readonly string $loanId,
        public readonly string $returnedByUserId,
        public readonly ?string $returnNote,
        public readonly string $returnedAt,
    ) {}
}
