<?php

namespace App\Domain\Notification\Projectors;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\Notification;
use App\Models\StoredEvent;
use Illuminate\Support\Facades\DB;

/**
 * notification.* イベントから notifications (個人通知一覧) を作成・更新する。
 * 主キーがUUID(`SendNotificationJob::enqueue()`側で発番)であるため、行の新規作成
 * (queued)自体もこのProjectorが担う (.claude/skills/add-projection「集約ルートのUUID化」参照)。
 */
class NotificationProjector implements Projector
{
    public function eventTypes(): array
    {
        return [
            'notification.queued',
            'notification.sent',
            'notification.confirmed',
        ];
    }

    public function project(StoredEvent $event): void
    {
        $payload = $event->payload;
        $id = $payload['notification_id'];

        match ($event->event_type) {
            'notification.queued' => Notification::query()->updateOrCreate(
                ['id' => $id],
                [
                    'recipient_user_id' => $payload['recipient_user_id'],
                    'title' => $payload['title'],
                    'summary' => $payload['summary'],
                    'detail_url' => $payload['detail_url'],
                    'queued_at' => $event->occurred_at,
                ],
            ),
            'notification.sent' => Notification::query()->whereKey($id)->update([
                'sent_at' => $event->occurred_at,
            ]),
            'notification.confirmed' => Notification::query()->whereKey($id)->update([
                'confirmed_at' => $event->occurred_at,
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        DB::table('notifications')->truncate();
    }
}
