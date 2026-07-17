<?php

namespace App\Domain\Attachment\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UploadAttachment implements Command
{
    public function __construct(
        public readonly string $ownerTypeAlias,
        public readonly int|string $ownerId,
        public readonly int $uploadedByUserId,
        public readonly string $fileName,
        public readonly string $storedPath,
        public readonly string $mimeType,
        public readonly int $fileSize,
    ) {}
}
