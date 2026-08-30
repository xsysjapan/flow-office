<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReportAssetLost implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $reportedByUserId,
        public readonly ?string $note = null,
    ) {}
}
