<?php

namespace App\Domain\ExpenseClaim\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Commands\CancelExpenseClaim;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * workflow_request(subject_type=expense_claim)の取消を受けて、実際のExpenseClaim集約へ
 * `CancelExpenseClaim`(viaReactor=true。既に取消済み・取消不可なら何もしない)を発行する。
 * 申請一覧からワークフローだけを取り消しても経費精算が申請中のまま残らないようにする。
 * 逆向き(経費精算の取消→ワークフローの取消)の連鎖とは、どちらも冪等なので往復しない。
 */
class CancelExpenseClaimOnWorkflowRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::EXPENSE_CLAIM
            || $workflowRequest->subject_id === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelExpenseClaim(
            claimId: $workflowRequest->subject_id,
            cancelledByUserId: $event->cancelledByUserId,
            reason: $event->reason,
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }
}
