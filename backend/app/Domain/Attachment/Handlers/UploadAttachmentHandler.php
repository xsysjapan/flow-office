<?php

namespace App\Domain\Attachment\Handlers;

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\Attachment\Events\AttachmentUploaded;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\Attachment;

/**
 * UC-F001: 添付ファイルをアップロードする。
 *
 * @implements CommandHandler<UploadAttachment>
 */
class UploadAttachmentHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Attachment
    {
        assert($command instanceof UploadAttachment);

        $attachment = Attachment::query()->create([
            'owner_type' => $command->ownerTypeAlias,
            'owner_id' => $command->ownerId,
            'uploaded_by' => $command->uploadedByUserId,
            'file_name' => $command->fileName,
            'stored_path' => $command->storedPath,
            'mime_type' => $command->mimeType,
            'file_size' => $command->fileSize,
        ]);

        $this->eventStore->append(
            aggregateType: 'attachment',
            aggregateId: (string) $attachment->id,
            event: new AttachmentUploaded(
                attachmentId: $attachment->id,
                ownerType: $command->ownerTypeAlias,
                ownerId: $command->ownerId,
                uploadedByUserId: $command->uploadedByUserId,
                fileName: $command->fileName,
                fileSize: $command->fileSize,
            ),
        );

        return $attachment;
    }
}
