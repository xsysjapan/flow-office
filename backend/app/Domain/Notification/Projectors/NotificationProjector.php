<?php

namespace App\Domain\Notification\Projectors;

use App\Domain\Notification\Events\NotificationConfirmed;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use App\Models\Notification;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * notification.* イベントから notifications (個人通知一覧) を作成・更新する。
 * 主キーがUUID(`SendNotificationJob::enqueue()`側で発番)であるため、行の新規作成
 * (queued)自体もこのProjectorが担う。
 */
class NotificationProjector extends Projector
{
    public function onNotificationQueued(NotificationQueued $event): void
    {
        Notification::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'recipient_user_id' => $event->recipientUserId,
                'title' => $event->title,
                'summary' => $event->summary,
                'detail_url' => $event->detailUrl,
                'queued_at' => $event->queuedAt,
            ],
        );
    }

    public function onNotificationSent(NotificationSent $event): void
    {
        Notification::query()->whereKey($event->aggregateRootUuid())->update([
            'sent_at' => $event->sentAt,
        ]);
    }

    public function onNotificationConfirmed(NotificationConfirmed $event): void
    {
        Notification::query()->whereKey($event->aggregateRootUuid())->update([
            'confirmed_at' => $event->confirmedAt,
        ]);
    }
}
