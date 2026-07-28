<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\DeleteExpenseClaim;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;

/**
 * UC-X010: 不要な下書きを削除する。申請前の下書き(draft)のみが対象で、一度でも
 * 申請・差戻しされた経費精算は履歴として残すため削除できない。
 *
 * @implements CommandHandler<DeleteExpenseClaim>
 */
class DeleteExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteExpenseClaim);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->employee_id !== $command->deletedByUserId) {
            throw new DomainRuleException('自分が作成した経費精算のみ削除できます。');
        }

        if ($claim->status !== ExpenseClaimStatus::DRAFT) {
            throw new DomainRuleException('下書き状態の経費精算のみ削除できます。');
        }

        ExpenseClaimAggregate::retrieve($claim->id)
            ->delete($command->deletedByUserId)
            ->persist();

        return null;
    }
}
