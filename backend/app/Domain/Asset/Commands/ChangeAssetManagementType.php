<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangeAssetManagementType implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $managementType,
        public readonly string $changedByUserId,
    ) {}
}
