<?php

namespace App\Domain\Notification\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * notification.queued。NotificationProjectorが集約UUID(aggregateRootUuid() =
 * notifications.id)をキーに行を新規作成する。
 * CommandHandlerのイベント追記と同一トランザクションでメール通知ジョブをDBキューに積んだ記録。
 */
class NotificationQueued extends ShouldBeStored
{
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $detailUrl,
        public readonly string $queuedAt,
    ) {}
}
