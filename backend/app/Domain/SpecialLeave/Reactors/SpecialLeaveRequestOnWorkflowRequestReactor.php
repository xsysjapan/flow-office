<?php

namespace App\Domain\SpecialLeave\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\SpecialLeave\Commands\ResubmitSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\ReturnSpecialLeaveRequest;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\LeaveRequestWorkflowLink;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 申請・承認文脈のワークフローのイベントを、特別休暇申請の集約へのCommandに変換する(休暇申請文脈のReactor。
 * 原則15)。ワークフローIDから特別休暇申請を特定するのは対応表(LeaveRequestWorkflowLinks)だけで、
 * workflow_requestsは読まない。
 *
 * - drafted: subject_type=special_leave_request の申請を、イベントの内容だけで申請する(`RequestSpecialLeave`)。
 * - submitted: 差戻し中の申請の再提出(`ResubmitSpecialLeaveRequest`。申請中なら何もしない)。
 * - returned: 差戻し(`ReturnSpecialLeaveRequest`)。
 * - cancelled: 申請・承認文脈での取消(`CancelSpecialLeaveRequest`。既に取消済みなら何もしない)。
 *
 * 全てviaReactor=trueで発行し、起点の操作者(initiatedByUserId)をイベントの操作者IDから引き継ぐ。
 */
class SpecialLeaveRequestOnWorkflowRequestReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LeaveRequestWorkflowLinks $links,
    ) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST
            || $event->subjectId === null
            || $event->approverUserId === null
        ) {
            return;
        }

        $formData = $event->formData;

        $this->commandBus->dispatch(new RequestSpecialLeave(
            userId: $event->applicantUserId,
            specialLeaveTypeId: (int) ($formData['special_leave_type_id'] ?? 0),
            targetDate: (string) ($formData['target_date'] ?? ''),
            leaveType: (string) ($formData['leave_type'] ?? ''),
            hours: isset($formData['hours']) ? (float) $formData['hours'] : null,
            approverUserId: $event->approverUserId,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
            requestId: $event->subjectId,
            requestGroupId: $formData['request_group_id'] ?? null,
            viaReactor: true,
            initiatedByUserId: $event->applicantUserId,
        ));
    }

    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        $specialLeaveRequestId = $this->specialLeaveRequestIdFor($event->aggregateRootUuid());
        if ($specialLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ResubmitSpecialLeaveRequest(
            specialLeaveRequestId: $specialLeaveRequestId,
            resubmittedByUserId: $event->submittedByUserId,
            viaReactor: true,
            initiatedByUserId: $event->submittedByUserId,
        ));
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $specialLeaveRequestId = $this->specialLeaveRequestIdFor($event->aggregateRootUuid());
        if ($specialLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ReturnSpecialLeaveRequest(
            specialLeaveRequestId: $specialLeaveRequestId,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $specialLeaveRequestId = $this->specialLeaveRequestIdFor($event->aggregateRootUuid());
        if ($specialLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelSpecialLeaveRequest(
            specialLeaveRequestId: $specialLeaveRequestId,
            cancelledByUserId: $event->cancelledByUserId,
            isAdminAction: false,
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    /** ワークフローIDに対応する特別休暇申請ID。特別休暇以外・対応表に無ければnull。 */
    private function specialLeaveRequestIdFor(string $workflowRequestId): ?string
    {
        $link = $this->links->find($workflowRequestId);

        if ($link === null || $link['leave_kind'] !== LeaveRequestWorkflowLink::KIND_SPECIAL) {
            return null;
        }

        return $link['leave_request_id'];
    }
}
