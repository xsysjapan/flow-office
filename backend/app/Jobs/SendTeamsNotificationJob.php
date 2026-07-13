<?php

namespace App\Jobs;

use App\Domain\EventSourcing\EventStore;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use App\Domain\Notification\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * UC-N001: Teams通知を送る。DBキューに積まれ、cronで起動する
 * `queue:work --stop-when-empty` によって処理される (docs/13-usecases-notification.md)。
 *
 * `enqueue()` はCommandHandler側のイベント追記と同一トランザクションで呼び出し、
 * `notification.queued` を記録してからジョブを積む(add-teams-notification スキル手順2)。
 * ジョブ本体(`handle`)は送信成功時のみ `notification.sent` を記録する。
 *
 * 注意: 静的メソッド名を`queue`にしてはいけない。Illuminate\Bus\Dispatcherは
 * `method_exists($command, 'queue')`でジョブ独自のキュー投入方法をフックする仕様のため、
 * 同名のメソッドを定義すると`(Queue $queue, $command)`で呼ばれてしまい衝突する。
 */
class SendTeamsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $detailUrl = null,
    ) {}

    public static function enqueue(string $title, string $summary, ?string $detailUrl = null): void
    {
        $notificationId = (string) Str::uuid();

        app(EventStore::class)->append(
            aggregateType: 'notification',
            aggregateId: $notificationId,
            event: new NotificationQueued($notificationId, $title, $summary, $detailUrl),
        );

        self::dispatch($notificationId, $title, $summary, $detailUrl);
    }

    public function handle(Notifier $notifier, EventStore $eventStore): void
    {
        $sent = $notifier->notify($this->title, $this->summary, $this->detailUrl);

        if (! $sent) {
            // 失敗はNotifier側で既にログ済み。自動リトライループは作らず、次回バッチでカバーする方針
            // (docs/13-usecases-notification.md、add-teams-notification スキル手順4)。
            return;
        }

        $eventStore->append(
            aggregateType: 'notification',
            aggregateId: $this->notificationId,
            event: new NotificationSent($this->notificationId, $this->title),
        );
    }
}
