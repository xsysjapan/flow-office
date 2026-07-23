<?php

namespace App\Domain\Attachment\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attachment.uploaded (UC-F001 手順5: アップロードイベントを記録する)。
 * event_class_map 経由で 'attachment.uploaded' という短い文字列として保存される
 * (config/event-sourcing.php参照)。
 */
class AttachmentUploaded extends ShouldBeStored
{
    public function __construct(
        public readonly string $attachmentId,
        public readonly string $ownerType,
        public readonly int|string $ownerId,
        public readonly int $uploadedByUserId,
        public readonly string $storedPath,
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly int $fileSize,
    ) {}
}
