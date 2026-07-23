<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * UC-P005/UC-P006: 消滅警告・年5日取得義務警告の履歴。
 */
class PaidLeaveWarningRaised extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $warningType,
        public readonly string $message,
    ) {}
}
