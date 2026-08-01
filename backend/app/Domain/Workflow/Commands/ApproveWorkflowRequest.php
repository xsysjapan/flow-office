<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W003: 承認者が申請を承認する。
 */
class ApproveWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        // nullは、経費精算のapproval_skip_threshold等による自動承認(承認者確認を1段階省略)を表す。
        public readonly ?string $approvedByUserId,
    ) {}
}
