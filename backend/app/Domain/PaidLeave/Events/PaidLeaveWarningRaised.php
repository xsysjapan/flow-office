<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * UC-P005/UC-P006: 消滅警告・年5日取得義務警告の履歴。
 */
class PaidLeaveWarningRaised implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveGrantId,
        public readonly int $userId,
        public readonly string $warningType,
        public readonly string $message,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.warning_raised';
    }

    public function payload(): array
    {
        return [
            'paid_leave_grant_id' => $this->paidLeaveGrantId,
            'user_id' => $this->userId,
            'warning_type' => $this->warningType,
            'message' => $this->message,
        ];
    }
}
