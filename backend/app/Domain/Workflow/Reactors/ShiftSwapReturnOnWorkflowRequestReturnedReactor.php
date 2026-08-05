<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Commands\ReturnShiftSwapRequest;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * workflow_request(subject_type=shift_swap_request)の差戻しを受けて、実際の
 * ShiftSwapRequest集約へ`ReturnShiftSwapRequest`を発行する。
 */
class ShiftSwapReturnOnWorkflowRequestReturnedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ReturnShiftSwapRequest(
            shiftSwapRequestId: $workflowRequest->subject_id,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
        ));
    }
}
