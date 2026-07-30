<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\UpdateExpenseClaimTitle;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;

/**
 * @implements CommandHandler<UpdateExpenseClaimTitle>
 */
class UpdateExpenseClaimTitleHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof UpdateExpenseClaimTitle);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->employee_id !== $command->updatedByUserId) {
            throw new DomainRuleException('自分の経費精算のみタイトルを変更できます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::editable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからはタイトルを変更できません。');
        }

        if ($claim->isLocked()) {
            throw new DomainRuleException('この経費精算は提出によりロックされているためタイトルを変更できません。');
        }

        ExpenseClaimAggregate::retrieve($claim->id)
            ->updateTitle($command->title)
            ->persist();

        return $claim->refresh();
    }
}
