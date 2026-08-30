<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class DisposeAsset implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $disposedByUserId,
        public readonly ?string $note = null,
    ) {}
}
