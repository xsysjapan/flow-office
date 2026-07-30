<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.locked (UC-X010)。提出時に明細を含むclaim全体を編集不可にする。
 * 差戻し(ExpenseClaimReturned)によりExpenseClaimUnlockedが記録されるまで解除されない。
 */
class ExpenseClaimLocked extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockedByUserId,
    ) {}
}
