<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X010 手順2: 経費明細を修正する。
 */
class UpdateExpenseItem implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $itemId,
        public readonly string $updatedByUserId,
        public readonly int $categoryId,
        public readonly ?string $usageDate,
        public readonly ?string $description,
        public readonly int $amount,
        public readonly ?string $projectId,
        public readonly ?string $evidenceType = null,
        public readonly ?string $factReferenceType = null,
        public readonly ?string $factReferenceId = null,
        public readonly int $commutingDeductionAmount = 0,
    ) {}
}
