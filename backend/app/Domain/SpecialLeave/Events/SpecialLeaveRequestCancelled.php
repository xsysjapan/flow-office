<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class SpecialLeaveRequestCancelled implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveRequestId,
        public readonly int $cancelledByUserId,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.request_cancelled';
    }

    public function payload(): array
    {
        return [
            'special_leave_request_id' => $this->specialLeaveRequestId,
            'cancelled_by_user_id' => $this->cancelledByUserId,
        ];
    }
}
