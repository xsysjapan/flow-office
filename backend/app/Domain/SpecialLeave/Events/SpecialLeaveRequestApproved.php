<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class SpecialLeaveRequestApproved implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveRequestId,
        public readonly int $approvedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.request_approved';
    }

    public function payload(): array
    {
        return [
            'special_leave_request_id' => $this->specialLeaveRequestId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
