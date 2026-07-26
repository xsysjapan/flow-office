<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.drafted。ExpenseClaimProjectorが集約UUID(aggregateRootUuid() =
 * expense_claims.id)をキーに行を新規作成する。
 */
class ExpenseClaimDrafted extends ShouldBeStored
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $periodFrom,
        public readonly string $periodTo,
    ) {}
}
