<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.cancelled。
 */
class ExpenseClaimCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
        public readonly string $reason,
    ) {}
}
