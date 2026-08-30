<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateAssetDetails implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $serialNumber,
        public readonly ?string $notes,
        public readonly string $updatedByUserId,
    ) {}
}
