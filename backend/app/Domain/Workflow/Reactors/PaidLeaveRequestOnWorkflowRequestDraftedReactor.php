<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P003: 有給申請は`DraftWorkflowRequest`(subject_type=paid_leave_request)を起点とし、
 * このReactorが実際のPaidLeaveRequest集約へ`RequestPaidLeave`を発行する
 * (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 *
 * PaidLeaveRequest側の結果(`PaidLeaveRequestShared`)を
 * SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor が受け、workflow_requestを提出済みにする。
 */
class PaidLeaveRequestOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->approver_user_id === null) {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];

        $this->commandBus->dispatch(new RequestPaidLeave(
            userId: $workflowRequest->applicant_user_id,
            targetDate: $formData['target_date'] ?? '',
            leaveType: $formData['leave_type'] ?? '',
            hours: isset($formData['hours']) ? (float) $formData['hours'] : null,
            approverUserId: $workflowRequest->approver_user_id,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
        ));
    }
}
