<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\DraftExpenseClaim;
use App\Models\ExpenseClaim;
use Illuminate\Support\Str;

/**
 * UC-X010: 経費精算の下書きを作成する。
 *
 * @implements CommandHandler<DraftExpenseClaim>
 */
class DraftExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof DraftExpenseClaim);

        $claimId = (string) Str::uuid();

        ExpenseClaimAggregate::retrieve($claimId)
            ->draft(employeeId: $command->employeeId)
            ->persist();

        return ExpenseClaim::query()->findOrFail($claimId);
    }
}
