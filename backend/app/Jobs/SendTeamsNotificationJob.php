<?php

namespace App\Jobs;

use App\Domain\Notification\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * UC-N001: Teams通知を送る。DBキューに積まれ、cronで起動する
 * `queue:work --stop-when-empty` によって処理される (docs/13-usecases-notification.md)。
 */
class SendTeamsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $detailUrl = null,
    ) {}

    public function handle(Notifier $notifier): void
    {
        $notifier->notify($this->title, $this->summary, $this->detailUrl);
    }
}
