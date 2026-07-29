<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\ReturnExpenseClaim;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;

/**
 * UC-X011 手順3: 経費精算を差し戻す。
 *
 * @implements CommandHandler<ReturnExpenseClaim>
 */
class ReturnExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof ReturnExpenseClaim);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->status !== ExpenseClaimStatus::IN_REVIEW) {
            throw new DomainRuleException('申請中の経費精算のみ差戻しできます。');
        }

        if ($claim->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        ExpenseClaimAggregate::retrieve($claim->id)
            ->returnClaim($command->returnedByUserId, $command->comment)
            ->persist();

        return $claim->refresh();
    }
}
