<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\ApproveExpenseClaim;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-X011: workflow_request(subject_type=expense_claim)の承認を受けて、
 * 実際のExpenseClaim集約へ`ApproveExpenseClaim`を発行する。
 */
class ExpenseClaimApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::EXPENSE_CLAIM) {
            return;
        }

        // approvedByUserIdがnullの場合、この承認はExpenseClaim側のapproval_skip_thresholdに
        // よる自動承認をApproveWorkflowRequestOnExpenseClaimAutoApprovedReactor経由で
        // 折り返しただけであり、ExpenseClaimは既に承認済みのため下流への伝播は不要
        // (ApproveExpenseClaimは承認者IDを必須とするコマンドでもある)。
        if ($event->approvedByUserId === null) {
            return;
        }

        $this->commandBus->dispatch(new ApproveExpenseClaim(
            claimId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));
    }
}
