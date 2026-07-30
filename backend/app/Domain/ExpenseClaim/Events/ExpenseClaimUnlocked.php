<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.unlocked (UC-X011)。差戻し時にExpenseClaimLockedによるロックを解除する。
 */
class ExpenseClaimUnlocked extends ShouldBeStored
{
    public function __construct(
        public readonly string $unlockedByUserId,
    ) {}
}
