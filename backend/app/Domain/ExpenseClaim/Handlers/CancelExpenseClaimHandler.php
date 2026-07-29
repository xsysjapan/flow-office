<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\CancelExpenseClaim;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;

/**
 * 経費精算を取り消す。
 *
 * @implements CommandHandler<CancelExpenseClaim>
 */
class CancelExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof CancelExpenseClaim);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->employee_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分が作成した経費精算のみ取り消せます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::cancellable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからは取り消せません。');
        }

        ExpenseClaimAggregate::retrieve($claim->id)
            ->cancel($command->cancelledByUserId, $command->reason)
            ->persist();

        return $claim->refresh();
    }
}
