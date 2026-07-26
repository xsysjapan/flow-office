<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X011: 経費精算を承認する。
 */
class ApproveExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $approvedByUserId,
    ) {}
}
