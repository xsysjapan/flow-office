<?php

namespace App\Domain\Notification\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * notification.sent (add-teams-notification スキル手順4)。
 * cron起動のqueue workerがTeams通知の送信に成功した記録。失敗時はログのみに留め、
 * このイベントは記録しない(自動リトライループを作らない方針)。
 */
class NotificationSent implements DomainEvent
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $title,
    ) {}

    public function eventType(): string
    {
        return 'notification.sent';
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'title' => $this->title,
        ];
    }
}
