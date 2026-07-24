<?php

namespace App\Domain\Attachment\Handlers;

use App\Domain\Attachment\Aggregates\AttachmentAggregate;
use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Attachment;
use Illuminate\Support\Str;

/**
 * UC-F001: 添付ファイルをアップロードする。
 *
 * @implements CommandHandler<UploadAttachment>
 */
class UploadAttachmentHandler implements CommandHandler
{
    public function handle(Command $command): Attachment
    {
        assert($command instanceof UploadAttachment);

        $attachmentId = (string) Str::uuid();

        AttachmentAggregate::retrieve($attachmentId)
            ->uploadAttachment(
                ownerType: $command->ownerTypeAlias,
                ownerId: $command->ownerId,
                uploadedByUserId: $command->uploadedByUserId,
                storedPath: $command->storedPath,
                fileName: $command->fileName,
                mimeType: $command->mimeType,
                fileSize: $command->fileSize,
            )
            ->persist();

        return Attachment::query()->findOrFail($attachmentId);
    }
}
