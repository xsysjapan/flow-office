<?php

namespace App\Domain\Notification\Aggregates;

use App\Domain\Notification\Events\NotificationConfirmed;
use App\Domain\Notification\Events\NotificationQueued;
use App\Domain\Notification\Events\NotificationSent;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * notification集約。主キーがコマンド側生成のUUID(`SendNotificationJob::enqueue()`側で発番)
 * のため、行の新規作成自体もNotificationProjectorに委ねられる
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
class NotificationAggregate extends AggregateRoot
{
    public function queue(string $recipientUserId, string $title, string $summary, ?string $detailUrl, string $queuedAt): self
    {
        $this->recordThat(new NotificationQueued(
            recipientUserId: $recipientUserId,
            title: $title,
            summary: $summary,
            detailUrl: $detailUrl,
            queuedAt: $queuedAt,
        ));

        return $this;
    }

    public function send(string $sentAt): self
    {
        $this->recordThat(new NotificationSent(sentAt: $sentAt));

        return $this;
    }

    public function confirm(string $confirmedByUserId, string $confirmedAt): self
    {
        $this->recordThat(new NotificationConfirmed(confirmedByUserId: $confirmedByUserId, confirmedAt: $confirmedAt));

        return $this;
    }
}
