<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当: CompensatoryLeaveRequest側の共有が完了したら、起点となった下書きの
 * workflow_requestを提出済みにする。承認依頼通知は SubmitWorkflowRequestHandler が
 * 一括して送るため、CompensatoryLeaveRequest側では通知を送らない。
 */
class SubmitWorkflowRequestOnCompensatoryLeaveRequestSharedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
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
