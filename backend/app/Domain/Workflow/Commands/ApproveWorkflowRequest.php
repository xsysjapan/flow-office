<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W003: 承認者が申請を承認する。
 */
class ApproveWorkflowRequest implements Command
{
    public function __construct(
        public readonly int $workflowRequestId,
        public readonly int $approvedByUserId,
    ) {}
}
