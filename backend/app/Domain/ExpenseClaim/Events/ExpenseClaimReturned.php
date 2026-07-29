<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.returned (UC-X011)。
 */
class ExpenseClaimReturned extends ShouldBeStored
{
    public function __construct(
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}
}
