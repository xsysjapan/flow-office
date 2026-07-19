<?php

namespace App\Domain\Notification\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Notification\Commands\ConfirmNotification;
use App\Domain\Notification\Events\NotificationConfirmed;
use App\Models\Notification;

/**
 * @implements CommandHandler<ConfirmNotification>
 */
class ConfirmNotificationHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Notification
    {
        assert($command instanceof ConfirmNotification);

        $notification = Notification::query()->findOrFail($command->notificationId);

        if ($notification->recipient_user_id !== $command->confirmedByUserId) {
            throw new DomainRuleException('自分宛ての通知のみ確認済みにできます。');
        }

        if ($notification->confirmed_at !== null) {
            return $notification;
        }

        $this->eventStore->append(
            aggregateType: 'notification',
            aggregateId: $notification->id,
            event: new NotificationConfirmed(
                notificationId: $notification->id,
                confirmedByUserId: $command->confirmedByUserId,
            ),
        );

        return $notification->refresh();
    }
}
