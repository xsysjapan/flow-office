<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestDrafted implements DomainEvent
{
    public function __construct(
        public readonly int $workflowRequestId,
        public readonly string $requestTypeCode,
        public readonly int $applicantUserId,
        public readonly string $title,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.drafted';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'request_type_code' => $this->requestTypeCode,
            'applicant_user_id' => $this->applicantUserId,
            'title' => $this->title,
        ];
    }
}
