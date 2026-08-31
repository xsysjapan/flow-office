<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ReturnAsset implements Command
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $loanId,
        public readonly string $returnedByUserId,
        public readonly ?string $returnNote = null,
    ) {}
}
