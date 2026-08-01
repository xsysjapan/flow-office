<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P003: SpecialLeaveRequest側の共有が完了したら、起点となった下書きの
 * workflow_requestを提出済みにする。承認依頼通知は SubmitWorkflowRequestHandler が
 * 一括して送るため、SpecialLeaveRequest側では通知を送らない。
 */
class SubmitWorkflowRequestOnSpecialLeaveRequestSharedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('id', $event->workflowRequestId)
            ->where('status', WorkflowRequestStatus::DRAFT)
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            submittedByUserId: $workflowRequest->applicant_user_id,
            approverUserId: $workflowRequest->approver_user_id,
        ));
    }
}
