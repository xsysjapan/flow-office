<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X012: 経費精算の承認を受けてバックオフィスタスクを自動作成する。
 * expense_claim.approved イベントを受けて
 * App\Domain\ExpenseClaim\Reactors\CreateBackOfficeTaskOnExpenseClaimApprovalReactor
 * から発行される。
 */
class CreateBackOfficeTaskFromExpenseClaimApproval implements Command
{
    public function __construct(public readonly string $expenseClaimId) {}
}
