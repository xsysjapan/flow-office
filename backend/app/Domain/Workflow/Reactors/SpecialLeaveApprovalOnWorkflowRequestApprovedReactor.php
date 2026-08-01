<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004: workflow_request(subject_type=special_leave_request)の承認を受けて、
 * 実際のSpecialLeaveRequest集約へ`ApproveSpecialLeaveRequest`を発行する。
 */
class SpecialLeaveApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApproveSpecialLeaveRequest(
            specialLeaveRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));
    }
}
