<?php

namespace App\Domain\Notification\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * notification.sent
 * cron起動のqueue workerがメール通知の送信に成功した記録。失敗時はログのみに留め、
 * このイベントは記録しない(自動リトライループを作らない方針)。
 */
class NotificationSent extends ShouldBeStored
{
    public function __construct(
        public readonly string $sentAt,
    ) {}
}
