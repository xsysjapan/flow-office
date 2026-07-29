<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.deleted。不要な下書きを削除する(UC-X010)。
 */
class ExpenseClaimDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
    ) {}
}
