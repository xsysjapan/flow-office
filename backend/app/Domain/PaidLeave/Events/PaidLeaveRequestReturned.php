<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class PaidLeaveRequestReturned implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.request_returned';
    }

    public function payload(): array
    {
        return [
            'paid_leave_request_id' => $this->paidLeaveRequestId,
            'returned_by_user_id' => $this->returnedByUserId,
            'comment' => $this->comment,
        ];
    }
}
