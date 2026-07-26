<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.submitted (UC-X010)。
 */
class ExpenseClaimSubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly string $approverUserId,
        public readonly string $submittedByUserId,
    ) {}
}
