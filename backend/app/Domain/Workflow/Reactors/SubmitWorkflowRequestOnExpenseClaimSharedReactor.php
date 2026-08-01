<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Events\ExpenseClaimShared;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-X010: ExpenseClaim側の提出(共有)が完了したら、起点となった下書きの
 * workflow_requestを提出済みにする。承認依頼通知は SubmitWorkflowRequestHandler が
 * 一括して送るため、ExpenseClaim側では通知を送らない。
 */
class SubmitWorkflowRequestOnExpenseClaimSharedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onExpenseClaimShared(ExpenseClaimShared $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::EXPENSE_CLAIM)
            ->where('subject_id', $event->aggregateRootUuid())
            ->where('status', WorkflowRequestStatus::DRAFT)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            submittedByUserId: $workflowRequest->applicant_user_id,
            approverUserId: $workflowRequest->approver_user_id,
        ));
    }
}
