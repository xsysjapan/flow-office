<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X010 手順2: 経費明細を削除する。
 */
class RemoveExpenseItem implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $itemId,
        public readonly string $removedByUserId,
    ) {}
}
