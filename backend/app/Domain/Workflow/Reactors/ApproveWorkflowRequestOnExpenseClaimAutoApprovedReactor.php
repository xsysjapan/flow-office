<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * expense_categories.approval_skip_threshold による自動承認(ExpenseClaimApprovedの
 * approvedByUserIdがnull)は、承認者が明示的に`WorkflowRequestApproved`を経由せず
 * ExpenseClaimAggregateが単独で発行する。この場合、対応するworkflow_requestが
 * submittedのまま取り残されないよう、ここから`ApproveWorkflowRequest`
 * (approvedByUserId: null)を発行して同期させる。
 *
 * 通常の(承認者操作による)承認はApproveWorkflowRequest→
 * ExpenseClaimApprovalOnWorkflowRequestApprovedReactorという逆方向の経路を通るため、
 * approvedByUserIdがnullでない場合はここでは何もしない(二重発行・ループ防止)。
 */
class ApproveWorkflowRequestOnExpenseClaimAutoApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        if ($event->approvedByUserId !== null) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::EXPENSE_CLAIM)
            ->where('subject_id', $event->aggregateRootUuid())
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            approvedByUserId: null,
        ));
    }
}
