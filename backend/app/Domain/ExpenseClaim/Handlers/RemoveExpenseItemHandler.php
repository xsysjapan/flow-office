<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\RemoveExpenseItem;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;

/**
 * UC-X010 手順2: 経費明細を削除する。
 *
 * @implements CommandHandler<RemoveExpenseItem>
 */
class RemoveExpenseItemHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RemoveExpenseItem);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);
        $item = ExpenseItem::query()->where('claim_id', $claim->id)->findOrFail($command->itemId);

        if ($claim->employee_id !== $command->removedByUserId) {
            throw new DomainRuleException('自分の経費精算にのみ明細を削除できます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::editable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからは明細を削除できません。');
        }

        ExpenseClaimAggregate::retrieve($claim->id)
            ->removeItem($item->id)
            ->persist();

        return null;
    }
}
