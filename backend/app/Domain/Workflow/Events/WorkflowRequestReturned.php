<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestReturned implements DomainEvent
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.returned';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'returned_by_user_id' => $this->returnedByUserId,
            'comment' => $this->comment,
        ];
    }
}
