<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W004: 承認者が差戻しする。
 */
class ReturnWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}
}
