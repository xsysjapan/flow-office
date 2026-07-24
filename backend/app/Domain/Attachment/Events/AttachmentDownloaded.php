<?php

namespace App\Domain\Attachment\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attachment.downloaded (docs/12-usecases-attachment.md UC-F002: 閲覧ログを監査ログに残す)。
 * 状態(attachmentsテーブル)を変更しない監査目的のイベント。
 */
class AttachmentDownloaded extends ShouldBeStored
{
    public function __construct(
        public readonly string $attachmentId,
        public readonly string $downloadedByUserId,
    ) {}
}
