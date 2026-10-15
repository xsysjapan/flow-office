<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Notifier;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * notifications.queued_at は表で最初の TIMESTAMP 列だったため、MySQL(explicit_defaults_for_timestamp=OFF)では
 * 既定値の自動付与で、送信・確認の投影(queued_at を含まないUPDATE)のたびに更新時刻へ上書きされた。
 * 列は DATETIME に変更済み(2026_10_14_000002)。送信・確認の後も payload の queuedAt のまま残ることを固定する。
 * (SQLite では ON UPDATE が起きないため、このテストは回帰の固定が目的。)
 */
class NotificationQueuedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_at_keeps_the_payload_value_after_sent_and_confirmed(): void
    {
        $this->app->instance(Notifier::class, new class implements Notifier
        {
            public function notify(User $recipient, string $title, string $summary, ?string $detailUrl): bool
            {
                return true;
            }
        });
        // 送信まで通すため、キュー接続を同期(sync)に固定する。.env の QUEUE_CONNECTION=database が
        // テスト実行時に優先されると、ジョブが jobs テーブルに積まれたまま実行されず sent_at が null になる。
        config(['queue.default' => 'sync']);
        $recipient = User::factory()->create();

        SendNotificationJob::enqueue($recipient, '件名', '概要', null);

        $notification = Notification::query()->firstOrFail();
        $payloadQueuedAt = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $notification->id)
            ->where('event_class', 'notification.queued')
            ->firstOrFail()
            ->event_properties['queuedAt'];
        $this->assertSame($payloadQueuedAt, DB::table('notifications')->where('id', $notification->id)->value('queued_at'));

        $notification->refresh();
        $this->assertNotNull($notification->sent_at);

        $this->actingAs($recipient)->postJson("/api/notifications/{$notification->id}/confirm")->assertOk();

        $this->assertNotNull(Notification::query()->findOrFail($notification->id)->confirmed_at);
        $this->assertSame(
            $payloadQueuedAt,
            DB::table('notifications')->where('id', $notification->id)->value('queued_at'),
        );
    }
}
