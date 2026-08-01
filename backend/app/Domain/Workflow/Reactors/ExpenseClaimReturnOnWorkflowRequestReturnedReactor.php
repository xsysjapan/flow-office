<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\ReturnExpenseClaim;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-X011 手順3: workflow_request(subject_type=expense_claim)の差戻しを受けて、
 * 実際のExpenseClaim集約へ`ReturnExpenseClaim`を発行する。
 */
class ExpenseClaimReturnOnWorkflowRequestReturnedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::EXPENSE_CLAIM) {
            return;
        }

        $this->commandBus->dispatch(new ReturnExpenseClaim(
            claimId: $workflowRequest->subject_id,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
        ));
    }
}
