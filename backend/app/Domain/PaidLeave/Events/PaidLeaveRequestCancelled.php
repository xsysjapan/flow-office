<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class PaidLeaveRequestCancelled implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $cancelledByUserId,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.request_cancelled';
    }

    public function payload(): array
    {
        return [
            'paid_leave_request_id' => $this->paidLeaveRequestId,
            'cancelled_by_user_id' => $this->cancelledByUserId,
        ];
    }
}
