<?php

namespace App\Domain\Notification\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * notification.confirmed
 * 本人が通知一覧から自分宛ての通知を確認済みにした記録。
 */
class NotificationConfirmed implements DomainEvent
{
    public function __construct(
        public readonly string $notificationId,
        public readonly int $confirmedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'notification.confirmed';
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'confirmed_by_user_id' => $this->confirmedByUserId,
        ];
    }
}
