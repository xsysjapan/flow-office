<?php

namespace App\Domain\Attachment\Projectors;

use App\Domain\Attachment\Events\AttachmentUploaded;
use App\Models\Attachment;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attachment.* イベントから attachments を作成する。主キーがUUID(コマンド側生成)のため、
 * 行の新規作成自体もこのProjectorが担う(docs/28-event-sourcing-framework-migration.md参照)。
 * attachment.downloaded は監査目的のみで状態を変更しないため、ここでは扱わない
 * (stored_eventsに記録された事実そのものが監査ログとなる)。
 */
class AttachmentProjector extends Projector
{
    public function onAttachmentUploaded(AttachmentUploaded $event): void
    {
        Attachment::query()->updateOrCreate(
            ['id' => $event->attachmentId],
            [
                'owner_type' => $event->ownerType,
                'owner_id' => $event->ownerId,
                'uploaded_by' => $event->uploadedByUserId,
                'file_name' => $event->fileName,
                'stored_path' => $event->storedPath,
                'mime_type' => $event->mimeType,
                'file_size' => $event->fileSize,
            ],
        );
    }
}
