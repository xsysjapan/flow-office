<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.drafted。ExpenseClaimProjectorが集約UUID(aggregateRootUuid() =
 * expense_claims.id)をキーに行を新規作成する。period_from/period_toは持たない
 * (明細のusage_dateから算出する派生値。docs/30-usecases-expense.md)。
 */
class ExpenseClaimDrafted extends ShouldBeStored
{
    public function __construct(
        public readonly string $employeeId,
    ) {}
}
