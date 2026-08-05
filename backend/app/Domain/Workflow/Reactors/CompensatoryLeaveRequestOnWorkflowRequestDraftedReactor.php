<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当: 代休の消化申請は`DraftWorkflowRequest`(subject_type=compensatory_leave_request)
 * を起点とし、このReactorが実際のCompensatoryLeaveRequest集約へ`RequestCompensatoryLeave`を
 * 発行する(ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 *
 * CompensatoryLeaveRequest側の結果(`CompensatoryLeaveRequestShared`)を
 * SubmitWorkflowRequestOnCompensatoryLeaveRequestSharedReactor が受け、workflow_requestを
 * 提出済みにする。
 */
class CompensatoryLeaveRequestOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->approver_user_id === null) {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];

        $this->commandBus->dispatch(new RequestCompensatoryLeave(
            userId: $workflowRequest->applicant_user_id,
            targetDate: $formData['target_date'] ?? '',
            leaveType: $formData['leave_type'] ?? '',
            hours: isset($formData['hours']) ? (float) $formData['hours'] : null,
            approverUserId: $workflowRequest->approver_user_id,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
            requestId: $workflowRequest->subject_id,
        ));
    }
}
