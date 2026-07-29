<?php

namespace App\Domain\ExpenseClaim\Reactors;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromExpenseClaimApproval;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-X012: expense_claim.approved を受けてバックオフィスタスクを自動作成する
 * (Workflowドメインの CreateBackOfficeTaskOnApprovalReactor と同じパターン)。
 */
class CreateBackOfficeTaskOnExpenseClaimApprovalReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        $this->commandBus->dispatch(new CreateBackOfficeTaskFromExpenseClaimApproval($event->aggregateRootUuid()));
    }
}
