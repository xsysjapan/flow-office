<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X004〜UC-X008: 経費明細を1件追加する。表形式一括入力・移動経路一括入力・テンプレートからの
 * まとめて追加はいずれもこのコマンドを複数回発行する形に統一する
 * (docs/30-usecases-expense.md「実装上のポイント」)。
 */
class AddExpenseItem implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $addedByUserId,
        public readonly int $categoryId,
        public readonly ?string $usageDate,
        public readonly ?string $description,
        public readonly int $amount,
        public readonly ?string $projectId,
        public readonly ?string $evidenceType = null,
        public readonly ?string $factReferenceType = null,
        public readonly ?string $factReferenceId = null,
        public readonly int $commutingDeductionAmount = 0,
        public readonly ?string $paymentBearer = null,
        public readonly ?array $attributes = null,
    ) {}
}
