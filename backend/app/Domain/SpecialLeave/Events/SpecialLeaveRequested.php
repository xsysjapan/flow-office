<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class SpecialLeaveRequested implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveRequestId,
        public readonly int $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly float $requestedDays,
        public readonly int $approverUserId,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.requested';
    }

    public function payload(): array
    {
        return [
            'special_leave_request_id' => $this->specialLeaveRequestId,
            'user_id' => $this->userId,
            'special_leave_type_id' => $this->specialLeaveTypeId,
            'target_date' => $this->targetDate,
            'leave_type' => $this->leaveType,
            'requested_days' => $this->requestedDays,
            'approver_user_id' => $this->approverUserId,
        ];
    }
}
