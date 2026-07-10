<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class PaidLeaveRequestApproved implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $approvedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.request_approved';
    }

    public function payload(): array
    {
        return [
            'paid_leave_request_id' => $this->paidLeaveRequestId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
