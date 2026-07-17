<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestApproved implements DomainEvent
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly int $approvedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.approved';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
