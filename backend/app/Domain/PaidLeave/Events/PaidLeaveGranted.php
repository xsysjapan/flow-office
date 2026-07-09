<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class PaidLeaveGranted implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveGrantId,
        public readonly int $userId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.granted';
    }

    public function payload(): array
    {
        return [
            'paid_leave_grant_id' => $this->paidLeaveGrantId,
            'user_id' => $this->userId,
            'granted_on' => $this->grantedOn,
            'expires_on' => $this->expiresOn,
            'granted_days' => $this->grantedDays,
        ];
    }
}
