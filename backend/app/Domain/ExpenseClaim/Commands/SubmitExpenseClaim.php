<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X010 手順3〜4: 承認者を指定して申請する。
 */
class SubmitExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $approverUserId,
        public readonly string $submittedByUserId,
    ) {}
}
