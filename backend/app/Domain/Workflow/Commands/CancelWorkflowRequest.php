<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W005: 申請者が申請を取り消す。
 */
class CancelWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly int $cancelledByUserId,
        public readonly string $reason,
    ) {}
}
