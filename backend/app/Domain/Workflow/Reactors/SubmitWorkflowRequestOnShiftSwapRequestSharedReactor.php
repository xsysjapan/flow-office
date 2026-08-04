<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestShared;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * ShiftSwapRequest側の共有が完了したら、起点となった下書きのworkflow_requestを提出済みに
 * する。承認依頼通知は SubmitWorkflowRequestHandler が一括して送るため、ShiftSwapRequest側
 * では通知を送らない。
 */
class SubmitWorkflowRequestOnShiftSwapRequestSharedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onShiftSwapRequestShared(ShiftSwapRequestShared $event): void
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
