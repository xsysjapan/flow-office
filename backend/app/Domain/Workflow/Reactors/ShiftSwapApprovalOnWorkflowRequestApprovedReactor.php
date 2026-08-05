<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Commands\ApproveShiftSwapRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * workflow_request(subject_type=shift_swap_request)の承認を受けて、実際の
 * ShiftSwapRequest集約へ`ApproveShiftSwapRequest`を発行する。
 */
class ShiftSwapApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApproveShiftSwapRequest(
            shiftSwapRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));
    }
}
