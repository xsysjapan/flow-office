<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Commands\RequestShiftSwap;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 振替休日申請は`DraftWorkflowRequest`(subject_type=shift_swap_request)を起点とし、
 * このReactorが実際のShiftSwapRequest集約へ`RequestShiftSwap`を発行する
 * (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 *
 * ShiftSwapRequest側の結果(`ShiftSwapRequestShared`)を
 * SubmitWorkflowRequestOnShiftSwapRequestSharedReactor が受け、workflow_requestを提出済みにする。
 */
class ShiftSwapRequestOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->approver_user_id === null) {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];

        $this->commandBus->dispatch(new RequestShiftSwap(
            userId: $workflowRequest->applicant_user_id,
            targetDate: $formData['target_date'] ?? '',
            substituteDate: $formData['substitute_date'] ?? '',
            approverUserId: $workflowRequest->approver_user_id,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
            requestId: $workflowRequest->subject_id,
        ));
    }
}
