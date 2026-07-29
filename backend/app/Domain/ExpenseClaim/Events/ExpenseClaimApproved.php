<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.approved (UC-X011)。approvedByUserIdがnullの場合は、
 * expense_categories.approval_skip_threshold により承認者確認を1段階省略した
 * 自動承認であることを表す(docs/30-usecases-expense.md「実装上のポイント」)。
 */
class ExpenseClaimApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
    ) {}
}
