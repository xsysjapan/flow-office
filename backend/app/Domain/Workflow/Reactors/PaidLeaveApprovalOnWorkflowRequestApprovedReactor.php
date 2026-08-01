<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004: workflow_request(subject_type=paid_leave_request)の承認を受けて、
 * 実際のPaidLeaveRequest集約へ`ApprovePaidLeaveRequest`を発行する。
 */
class PaidLeaveApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApprovePaidLeaveRequest(
            paidLeaveRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));
    }
}
