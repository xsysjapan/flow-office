<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangeAssetLendingMethod implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $lendingMethod,
        public readonly string $changedByUserId,
    ) {}
}
