<?php

namespace App\Domain\Workflow\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class WorkflowRequestDrafted implements DomainEvent
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly int $requestTypeId,
        public readonly string $requestTypeCode,
        public readonly int $applicantUserId,
        public readonly string $title,
        public readonly array $formData,
        public readonly ?int $approverUserId,
    ) {}

    public function eventType(): string
    {
        return 'workflow_request.drafted';
    }

    public function payload(): array
    {
        return [
            'workflow_request_id' => $this->workflowRequestId,
            'request_type_id' => $this->requestTypeId,
            'request_type_code' => $this->requestTypeCode,
            'applicant_user_id' => $this->applicantUserId,
            'title' => $this->title,
            'form_data' => $this->formData,
            'approver_user_id' => $this->approverUserId,
        ];
    }
}
