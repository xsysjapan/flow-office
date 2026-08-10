<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P003: 特別休暇申請は`DraftWorkflowRequest`(subject_type=special_leave_request)を起点とし、
 * このReactorが実際のSpecialLeaveRequest集約へ`RequestSpecialLeave`を発行する
 * (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 *
 * SpecialLeaveRequest側の結果(`SpecialLeaveRequestShared`)を
 * SubmitWorkflowRequestOnSpecialLeaveRequestSharedReactor が受け、workflow_requestを提出済みにする。
 */
class SpecialLeaveRequestOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->approver_user_id === null) {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];

        $this->commandBus->dispatch(new RequestSpecialLeave(
            userId: $workflowRequest->applicant_user_id,
            specialLeaveTypeId: (int) ($formData['special_leave_type_id'] ?? 0),
            targetDate: $formData['target_date'] ?? '',
            leaveType: $formData['leave_type'] ?? '',
            hours: isset($formData['hours']) ? (float) $formData['hours'] : null,
            approverUserId: $workflowRequest->approver_user_id,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
            requestId: $workflowRequest->subject_id,
            requestGroupId: $formData['request_group_id'] ?? null,
        ));
    }
}
