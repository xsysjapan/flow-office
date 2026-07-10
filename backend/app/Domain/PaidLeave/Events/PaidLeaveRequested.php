<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class PaidLeaveRequested implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly float $requestedDays,
        public readonly int $approverUserId,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.requested';
    }

    public function payload(): array
    {
        return [
            'paid_leave_request_id' => $this->paidLeaveRequestId,
            'user_id' => $this->userId,
            'target_date' => $this->targetDate,
            'leave_type' => $this->leaveType,
            'requested_days' => $this->requestedDays,
            'approver_user_id' => $this->approverUserId,
        ];
    }
}
