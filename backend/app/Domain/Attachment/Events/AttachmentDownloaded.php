<?php

namespace App\Domain\Attachment\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attachment.downloaded (docs/12-usecases-attachment.md UC-F002: 閲覧ログを監査ログに残す)。
 */
class AttachmentDownloaded implements DomainEvent
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly int $downloadedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attachment.downloaded';
    }

    public function payload(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'downloaded_by_user_id' => $this->downloadedByUserId,
        ];
    }
}
