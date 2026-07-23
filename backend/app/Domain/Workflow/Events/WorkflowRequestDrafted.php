<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * workflow_request.drafted。WorkflowRequestProjectorが集約UUID(aggregateRootUuid() =
 * workflow_requests.id)をキーに行を新規作成する。
 */
class WorkflowRequestDrafted extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public readonly int $requestTypeId,
        public readonly string $requestTypeCode,
        public readonly int $applicantUserId,
        public readonly string $title,
        public readonly array $formData,
        public readonly ?int $approverUserId,
    ) {}
}
