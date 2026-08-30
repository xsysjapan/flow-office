<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class SetAssetDefaultLocation implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $locationText,
        public readonly string $setByUserId,
    ) {}
}
