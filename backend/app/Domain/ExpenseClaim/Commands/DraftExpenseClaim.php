<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X004/UC-X010: 経費精算の下書きを作成する。対象期間は明細のusage_dateから
 * ExpenseClaimProjectorが自動算出する派生値のため、下書き作成時には受け取らない
 * (docs/30-usecases-expense.md)。
 */
class DraftExpenseClaim implements Command
{
    public function __construct(
        public readonly string $employeeId,
    ) {}
}
