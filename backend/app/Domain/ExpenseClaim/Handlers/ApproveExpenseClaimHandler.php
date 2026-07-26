<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\ApproveExpenseClaim;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;

/**
 * UC-X011: 経費精算を承認する。
 *
 * @implements CommandHandler<ApproveExpenseClaim>
 */
class ApproveExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof ApproveExpenseClaim);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->status !== ExpenseClaimStatus::IN_REVIEW) {
            throw new DomainRuleException('申請中の経費精算のみ承認できます。');
        }

        if ($claim->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // このイベントを CreateBackOfficeTaskOnExpenseClaimApprovalReactor が購読し、
        // バックオフィスタスクを自動生成する (UC-X012)。
        ExpenseClaimAggregate::retrieve($claim->id)
            ->approve($command->approvedByUserId)
            ->persist();

        return $claim->refresh();
    }
}
