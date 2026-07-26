<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X010: 経費精算の対象期間で下書きを作成する。
 */
class DraftExpenseClaim implements Command
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $periodFrom,
        public readonly string $periodTo,
    ) {}
}
