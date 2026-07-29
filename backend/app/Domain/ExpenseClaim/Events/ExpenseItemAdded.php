<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.item_added。明細はexpense_claim集約内部の状態として扱うため、
 * 集約UUID(expense_claims.id)とは別にitemId(expense_items.id)を持つ。
 */
class ExpenseItemAdded extends ShouldBeStored
{
    public function __construct(
        public readonly string $itemId,
        public readonly int $categoryId,
        public readonly ?string $usageDate,
        public readonly ?string $description,
        public readonly int $amount,
        public readonly ?string $projectId,
        public readonly string $evidenceType,
        public readonly ?string $factReferenceType,
        public readonly ?string $factReferenceId,
        public readonly int $commutingDeductionAmount,
        public readonly string $paymentBearer,
        public readonly ?array $attributes,
    ) {}
}
