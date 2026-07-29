<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X010: 不要な下書き(まだ申請していない経費精算)を削除する。
 */
class DeleteExpenseClaim implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $deletedByUserId,
    ) {}
}
