<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当: workflow_request(subject_type=compensatory_leave_request)の承認を受けて、
 * 実際のCompensatoryLeaveRequest集約へ`ApproveCompensatoryLeaveRequest`を発行する。
 */
class CompensatoryLeaveApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApproveCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));
    }
}
