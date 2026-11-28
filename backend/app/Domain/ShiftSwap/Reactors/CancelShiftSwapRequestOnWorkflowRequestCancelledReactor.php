<?php

namespace App\Domain\ShiftSwap\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Commands\CancelShiftSwapRequest;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * workflow_request(subject_type=shift_swap_request)の取消を受けて、実際のShiftSwapRequest集約へ
 * `CancelShiftSwapRequest`(viaReactor=true。提出済み以外なら何もしない)を発行する。
 * 逆向き(振替休日申請の取消→ワークフローの取消)の連鎖とは、どちらも冪等なので往復しない。
 */
class CancelShiftSwapRequestOnWorkflowRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST
            || $workflowRequest->subject_id === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelShiftSwapRequest(
            shiftSwapRequestId: $workflowRequest->subject_id,
            cancelledByUserId: $event->cancelledByUserId,
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }
}
