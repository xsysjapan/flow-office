<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W002: 社員が申請する(申請)。承認者は都度指定できる。
 */
class SubmitWorkflowRequest implements Command
{
    public function __construct(
        public readonly int $workflowRequestId,
        public readonly int $submittedByUserId,
        public readonly ?int $approverUserId = null,
    ) {}
}
