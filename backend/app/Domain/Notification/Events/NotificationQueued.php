<?php

namespace App\Domain\Notification\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * notification.queued
 * CommandHandlerのイベント追記と同一トランザクションでメール通知ジョブをDBキューに積んだ記録。
 */
class NotificationQueued implements DomainEvent
{
    public function __construct(
        public readonly string $notificationId,
        public readonly int $recipientUserId,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $detailUrl,
    ) {}

    public function eventType(): string
    {
        return 'notification.queued';
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'recipient_user_id' => $this->recipientUserId,
            'title' => $this->title,
            'summary' => $this->summary,
            'detail_url' => $this->detailUrl,
        ];
    }
}
