<?php

namespace App\Domain\Notification\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-N001: 本人が自分宛ての通知を確認済みにする。
 */
class ConfirmNotification implements Command
{
    public function __construct(
        public readonly string $notificationId,
        public readonly int $confirmedByUserId,
    ) {}
}
