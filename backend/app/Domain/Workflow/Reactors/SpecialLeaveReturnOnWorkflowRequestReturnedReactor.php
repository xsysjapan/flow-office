<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\ReturnSpecialLeaveRequest;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004 手順2: workflow_request(subject_type=special_leave_request)の差戻しを受けて、
 * 実際のSpecialLeaveRequest集約へ`ReturnSpecialLeaveRequest`を発行する。
 */
class SpecialLeaveReturnOnWorkflowRequestReturnedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ReturnSpecialLeaveRequest(
            specialLeaveRequestId: $workflowRequest->subject_id,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
        ));
    }
}
