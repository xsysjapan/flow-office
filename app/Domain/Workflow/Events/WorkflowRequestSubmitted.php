<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestSubmitted implements DomainEvent
{
    public function __construct(
        public readonly int $workflowRequestId,
        public readonly int $approverUserId,
        public readonly int $submittedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.submitted';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'approver_user_id' => $this->approverUserId,
            'submitted_by_user_id' => $this->submittedByUserId,
        ];
    }
}
