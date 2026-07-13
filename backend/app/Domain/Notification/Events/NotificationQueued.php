<?php

namespace App\Domain\Notification\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * notification.queued (add-teams-notification スキル手順2)。
 * CommandHandlerのイベント追記と同一トランザクションでTeams通知ジョブをDBキューに積んだ記録。
 */
class NotificationQueued implements DomainEvent
{
    public function __construct(
        public readonly string $notificationId,
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
            'title' => $this->title,
            'summary' => $this->summary,
            'detail_url' => $this->detailUrl,
        ];
    }
}
