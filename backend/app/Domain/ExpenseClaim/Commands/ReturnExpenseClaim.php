<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X011 手順3: 経費精算を差し戻す。
 */
class ReturnExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
