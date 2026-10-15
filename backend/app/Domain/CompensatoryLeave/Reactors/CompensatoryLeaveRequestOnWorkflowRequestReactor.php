<?php

namespace App\Domain\CompensatoryLeave\Reactors;

use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\CompensatoryLeave\Commands\ResubmitCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\ReturnCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\LeaveRequestWorkflowLink;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 申請・承認文脈のワークフローのイベントを、代休申請の集約へのCommandに変換する(休暇申請文脈のReactor。
 * 原則15)。ワークフローIDから代休申請を特定するのは対応表(LeaveRequestWorkflowLinks)だけで、
 * workflow_requestsは読まない。SpecialLeaveRequestOnWorkflowRequestReactorと同じ形。
 *
 * - drafted: subject_type=compensatory_leave_request の申請を、イベントの内容だけで申請する(`RequestCompensatoryLeave`)。
 * - submitted: 差戻し中の申請の再提出(`ResubmitCompensatoryLeaveRequest`。申請中なら何もしない)。
 * - returned: 差戻し(`ReturnCompensatoryLeaveRequest`)。
 * - cancelled: 申請・承認文脈での取消(`CancelCompensatoryLeaveRequest`。既に取消済みなら何もしない)。
 *
 * 全てviaReactor=trueで発行し、起点の操作者(initiatedByUserId)をイベントの操作者IDから引き継ぐ。
 */
class CompensatoryLeaveRequestOnWorkflowRequestReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LeaveRequestWorkflowLinks $links,
    ) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST
            || $event->subjectId === null
            || $event->approverUserId === null
        ) {
            return;
        }

        $formData = $event->formData;

        $this->commandBus->dispatch(new RequestCompensatoryLeave(
            userId: $event->applicantUserId,
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
        $compensatoryLeaveRequestId = $this->compensatoryLeaveRequestIdFor($event->aggregateRootUuid());
        if ($compensatoryLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ResubmitCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $compensatoryLeaveRequestId,
            resubmittedByUserId: $event->submittedByUserId,
            viaReactor: true,
            initiatedByUserId: $event->submittedByUserId,
        ));
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $compensatoryLeaveRequestId = $this->compensatoryLeaveRequestIdFor($event->aggregateRootUuid());
        if ($compensatoryLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ReturnCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $compensatoryLeaveRequestId,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $compensatoryLeaveRequestId = $this->compensatoryLeaveRequestIdFor($event->aggregateRootUuid());
        if ($compensatoryLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $compensatoryLeaveRequestId,
            cancelledByUserId: $event->cancelledByUserId,
            isAdminAction: false,
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    /** ワークフローIDに対応する代休申請ID。代休以外・対応表に無ければnull。 */
    private function compensatoryLeaveRequestIdFor(string $workflowRequestId): ?string
    {
        $link = $this->links->find($workflowRequestId);

        if ($link === null || $link['leave_kind'] !== LeaveRequestWorkflowLink::KIND_COMPENSATORY) {
            return null;
        }

        return $link['leave_request_id'];
    }
}
