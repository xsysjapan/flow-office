<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * このイベントを App\Domain\Workflow\Reactors\CreateBackOfficeTaskOnApprovalReactor が購読し、
 * 必要な申請種別ならバックオフィスタスクを自動生成する (UC-B001)。
 */
class WorkflowRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly int $approvedByUserId,
    ) {}
}
