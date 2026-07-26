<?php

namespace App\Domain\ExpenseClaim\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.item_updated。
 */
class ExpenseItemUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $itemId,
        public readonly int $categoryId,
        public readonly ?string $usageDate,
        public readonly ?string $origin,
        public readonly ?string $destination,
        public readonly ?string $transportType,
        public readonly int $amount,
        public readonly ?string $destinationName,
        public readonly ?string $purpose,
        public readonly ?string $projectId,
        public readonly string $evidenceType,
        public readonly ?string $factReferenceType,
        public readonly ?string $factReferenceId,
        public readonly int $commutingDeductionAmount,
    ) {}
}
