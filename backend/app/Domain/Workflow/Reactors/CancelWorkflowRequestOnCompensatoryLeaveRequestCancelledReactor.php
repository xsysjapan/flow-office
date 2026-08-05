<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当: 代休申請の取消はCompensatoryLeaveRequest集約側で行われるため、起点となった
 * workflow_requestがsubmittedのまま取り残され、統合申請一覧に承認待ちとして
 * 残り続けてしまう。ここから`CancelWorkflowRequest`を発行して同期させる。
 */
class CancelWorkflowRequestOnCompensatoryLeaveRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST)
            ->where('subject_id', $event->aggregateRootUuid())
            ->whereIn('status', WorkflowRequestStatus::cancellable())
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $event->cancelledByUserId,
            reason: '代休申請が取り消されました。',
        ));
    }
}
