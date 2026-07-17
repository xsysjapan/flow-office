<?php

namespace App\Domain\Attachment\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attachment.uploaded (UC-F001 手順5: アップロードイベントを記録する)。
 */
class AttachmentUploaded implements DomainEvent
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $ownerType,
        public readonly int|string $ownerId,
        public readonly int $uploadedByUserId,
        public readonly string $fileName,
        public readonly int $fileSize,
    ) {}

    public function eventType(): string
    {
        return 'attachment.uploaded';
    }

    public function payload(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'uploaded_by_user_id' => $this->uploadedByUserId,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
        ];
    }
}
