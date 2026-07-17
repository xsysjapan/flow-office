<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class SpecialLeaveGranted implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveGrantId,
        public readonly int $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.granted';
    }

    public function payload(): array
    {
        return [
            'special_leave_grant_id' => $this->specialLeaveGrantId,
            'user_id' => $this->userId,
            'special_leave_type_id' => $this->specialLeaveTypeId,
            'granted_on' => $this->grantedOn,
            'expires_on' => $this->expiresOn,
            'granted_days' => $this->grantedDays,
        ];
    }
}
