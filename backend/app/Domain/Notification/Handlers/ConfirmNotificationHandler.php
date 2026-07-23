<?php

namespace App\Domain\Notification\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Notification\Aggregates\NotificationAggregate;
use App\Domain\Notification\Commands\ConfirmNotification;
use App\Models\Notification;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<ConfirmNotification>
 */
class ConfirmNotificationHandler implements CommandHandler
{
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

        NotificationAggregate::retrieve($notification->id)
            ->confirm($command->confirmedByUserId, Carbon::now()->format('Y-m-d H:i:s'))
            ->persist();

        return $notification->refresh();
    }
}
