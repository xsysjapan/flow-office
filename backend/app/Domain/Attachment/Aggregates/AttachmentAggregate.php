<?php

namespace App\Domain\Attachment\Aggregates;

use App\Domain\Attachment\Events\AttachmentDownloaded;
use App\Domain\Attachment\Events\AttachmentUploaded;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * attachment集約(uuid = attachments.id)。UploadAttachmentHandler/AttachmentControllerが
 * これを経由してイベントを記録し、AttachmentProjectorがattachmentsテーブルへ反映する。
 */
class AttachmentAggregate extends AggregateRoot
{
    public function uploadAttachment(
        string $ownerType,
        int|string $ownerId,
        int $uploadedByUserId,
        string $storedPath,
        string $fileName,
        string $mimeType,
        int $fileSize,
    ): self {
        $this->recordThat(new AttachmentUploaded(
            attachmentId: $this->uuid(),
            ownerType: $ownerType,
            ownerId: $ownerId,
            uploadedByUserId: $uploadedByUserId,
            storedPath: $storedPath,
            fileName: $fileName,
            mimeType: $mimeType,
            fileSize: $fileSize,
        ));

        return $this;
    }

    public function downloadAttachment(int $downloadedByUserId): self
    {
        $this->recordThat(new AttachmentDownloaded(
            attachmentId: $this->uuid(),
            downloadedByUserId: $downloadedByUserId,
        ));

        return $this;
    }
}
