<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 経費精算を取り消す。
 */
class CancelExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $cancelledByUserId,
        public readonly string $reason,
    ) {}
}
