<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\SubmitExpenseClaim;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-X010: 経費精算の提出は`DraftWorkflowRequest`(subject_type=expense_claim)を起点とし、
 * このReactorが実際のExpenseClaim集約へ`SubmitExpenseClaim`を発行する
 * (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 *
 * ExpenseClaim側の提出結果(`ExpenseClaimShared`)を
 * SubmitWorkflowRequestOnExpenseClaimSharedReactor が受け、workflow_requestを提出済みにする。
 */
class ExpenseClaimSubmitOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::EXPENSE_CLAIM) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->subject_id === null || $workflowRequest->approver_user_id === null) {
            return;
        }

        $this->commandBus->dispatch(new SubmitExpenseClaim(
            claimId: $workflowRequest->subject_id,
            approverUserId: $workflowRequest->approver_user_id,
            submittedByUserId: $workflowRequest->applicant_user_id,
        ));
    }
}
