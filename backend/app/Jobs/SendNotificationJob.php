<?php

namespace App\Jobs;

use App\Domain\EventSourcing\EventStore;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use App\Domain\Notification\Notifier;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * UC-N001: メール通知を送る。DBキューに積まれ、cronで起動する
 * `queue:work --stop-when-empty` によって処理される (docs/13-usecases-notification.md)。
 *
 * `enqueue()` はCommandHandler側のイベント追記と同一トランザクションで呼び出し、
 * `notification.queued` を記録してからジョブを積む。
 * ジョブ本体(`handle`)は送信成功時のみ `notification.sent` を記録する。
 *
 * 注意: 静的メソッド名を`queue`にしてはいけない。Illuminate\Bus\Dispatcherは
 * `method_exists($command, 'queue')`でジョブ独自のキュー投入方法をフックする仕様のため、
 * 同名のメソッドを定義すると`(Queue $queue, $command)`で呼ばれてしまい衝突する。
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $notificationId,
        public readonly int $recipientUserId,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $detailUrl = null,
    ) {}

    public static function enqueue(User $recipient, string $title, string $summary, ?string $detailUrl = null): void
    {
        $notificationId = (string) Str::uuid();

        app(EventStore::class)->append(
            aggregateType: 'notification',
            aggregateId: $notificationId,
            event: new NotificationQueued($notificationId, $recipient->id, $title, $summary, $detailUrl),
        );

        self::dispatch($notificationId, $recipient->id, $title, $summary, $detailUrl);
    }

    public function handle(Notifier $notifier, EventStore $eventStore): void
    {
        $recipient = User::find($this->recipientUserId);

        if ($recipient === null) {
            // 対象ユーザーが削除済み(退職処理等)の場合は送りようがないため何もしない。
            return;
        }

        $sent = $notifier->notify($recipient, $this->title, $this->summary, $this->detailUrl);

        if (! $sent) {
            // 失敗はNotifier側で既にログ済み。自動リトライループは作らず、次回バッチでカバーする方針
            // (docs/13-usecases-notification.md)。
            return;
        }

        $eventStore->append(
            aggregateType: 'notification',
            aggregateId: $this->notificationId,
            event: new NotificationSent($this->notificationId, $this->recipientUserId, $this->title),
        );
    }
}
