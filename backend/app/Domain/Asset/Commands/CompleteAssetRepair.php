<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CompleteAssetRepair implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $completedByUserId,
        public readonly ?string $note = null,
    ) {}
}
