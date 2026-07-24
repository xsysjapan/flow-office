<?php

namespace App\Domain\Notification\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * notification.confirmed
 * 本人が通知一覧から自分宛ての通知を確認済みにした記録。
 */
class NotificationConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $confirmedByUserId,
        public readonly string $confirmedAt,
    ) {}
}
