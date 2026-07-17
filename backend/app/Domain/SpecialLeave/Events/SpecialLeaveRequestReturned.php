<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class SpecialLeaveRequestReturned implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.request_returned';
    }

    public function payload(): array
    {
        return [
            'special_leave_request_id' => $this->specialLeaveRequestId,
            'returned_by_user_id' => $this->returnedByUserId,
            'comment' => $this->comment,
        ];
    }
}
