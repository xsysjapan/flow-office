<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.title_updated。
 */
class ExpenseClaimTitleUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $title,
    ) {}
}
