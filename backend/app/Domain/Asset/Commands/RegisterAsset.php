<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RegisterAsset implements Command
{
    public function __construct(
        public readonly ?string $assetNo,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $serialNumber,
        public readonly string $managementType,
        public readonly ?string $lendingMethod,
        public readonly ?string $defaultLocationText,
        public readonly ?string $notes,
        public readonly string $registeredByUserId,
    ) {}
}
