<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestCancelled implements DomainEvent
{
    public function __construct(
        public readonly int $workflowRequestId,
        public readonly int $cancelledByUserId,
        public readonly string $reason,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.cancelled';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'cancelled_by_user_id' => $this->cancelledByUserId,
            'reason' => $this->reason,
        ];
    }
}
