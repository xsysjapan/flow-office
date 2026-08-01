<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * workflow_request.drafted。WorkflowRequestProjectorが集約UUID(aggregateRootUuid() =
 * workflow_requests.id)をキーに行を新規作成する。
 *
 * 他ドメインを対象とする申請(`subjectType`が`attendance_month`/`expense_claim`)の場合、
 * 申請種別マスタを持たないため`requestTypeId`/`requestTypeCode`はnullになる。
 */
class WorkflowRequestDrafted extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public readonly ?int $requestTypeId,
        public readonly ?string $requestTypeCode,
        public readonly string $applicantUserId,
        public readonly string $title,
        public readonly array $formData,
        public readonly ?string $approverUserId,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
    ) {}
}
