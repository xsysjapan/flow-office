<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Commands\ReturnCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当 手順2: workflow_request(subject_type=compensatory_leave_request)の差戻しを受けて、
 * 実際のCompensatoryLeaveRequest集約へ`ReturnCompensatoryLeaveRequest`を発行する。
 */
class CompensatoryLeaveReturnOnWorkflowRequestReturnedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ReturnCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $workflowRequest->subject_id,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
        ));
    }
}
