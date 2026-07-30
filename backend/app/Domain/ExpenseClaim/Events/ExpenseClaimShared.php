<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.shared (UC-X010)。提出時に明細を含むclaim全体を承認者へ開示したことを表す。
 * ExpenseClaimProjectorの反映処理でentity_sharesへ追記される(App\Models\EntityShare)。
 */
class ExpenseClaimShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $sharedWithUserId,
        public readonly string $sharedByUserId,
    ) {}
}
