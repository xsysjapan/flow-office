<?php

namespace App\Domain\Attachment\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * `attachmentId`は入口・Reactorが先に決めたID(経費の明細と一緒に追加する添付)。省略時はHandlerが採番する。
 * `viaReactor`=trueのとき、そのIDの添付が既にあれば何もしない(冪等)。
 */
class UploadAttachment implements Command
{
    public function __construct(
        public readonly string $ownerTypeAlias,
        public readonly int|string $ownerId,
        public readonly string $uploadedByUserId,
        public readonly string $fileName,
        public readonly string $storedPath,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly ?string $attachmentId = null,
        public readonly bool $viaReactor = false,
    ) {}
}
