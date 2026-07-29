<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.item_removed。
 */
class ExpenseItemRemoved extends ShouldBeStored
{
    public function __construct(
        public readonly string $itemId,
    ) {}
}
