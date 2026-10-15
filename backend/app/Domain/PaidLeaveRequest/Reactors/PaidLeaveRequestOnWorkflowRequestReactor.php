<?php

namespace App\Domain\PaidLeaveRequest\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Commands\ResubmitPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\LeaveRequestWorkflowLink;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 申請・承認文脈のワークフローのイベントを、有給申請の集約へのCommandに変換する(休暇申請文脈のReactor。
 * 原則15)。ワークフローIDから有給申請を特定するのは対応表(LeaveRequestWorkflowLinks)だけで、
 * workflow_requestsは読まない。
 *
 * - drafted: subject_type=paid_leave_request の申請を、イベントの内容だけで申請する(`RequestPaidLeave`)。
 * - submitted: 差戻し中の申請の再提出(`ResubmitPaidLeaveRequest`。申請中なら何もしない)。
 * - returned: 差戻し(`ReturnPaidLeaveRequest`)。
 * - cancelled: 申請・承認文脈での取消(`CancelPaidLeaveRequest`。既に取消済みなら何もしない)。
 *
 * 全てviaReactor=trueで発行し、起点の操作者(initiatedByUserId)をイベントの操作者IDから引き継ぐ。
 */
class PaidLeaveRequestOnWorkflowRequestReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LeaveRequestWorkflowLinks $links,
    ) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST
            || $event->subjectId === null
            || $event->approverUserId === null
        ) {
            return;
        }

        $formData = $event->formData;

        $this->commandBus->dispatch(new RequestPaidLeave(
            requestId: $event->subjectId,
            userId: $event->applicantUserId,
            targetDate: (string) ($formData['target_date'] ?? ''),
            leaveType: (string) ($formData['leave_type'] ?? ''),
            hours: isset($formData['hours']) ? (float) $formData['hours'] : null,
            approverUserId: $event->approverUserId,
            reason: $formData['reason'] ?? null,
            workflowRequestId: $event->aggregateRootUuid(),
            requestGroupId: $formData['request_group_id'] ?? null,
            viaReactor: true,
            initiatedByUserId: $event->applicantUserId,
        ));
    }

    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        $paidLeaveRequestId = $this->paidLeaveRequestIdFor($event->aggregateRootUuid());
        if ($paidLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ResubmitPaidLeaveRequest(
            paidLeaveRequestId: $paidLeaveRequestId,
            resubmittedByUserId: $event->submittedByUserId,
            viaReactor: true,
            initiatedByUserId: $event->submittedByUserId,
        ));
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $paidLeaveRequestId = $this->paidLeaveRequestIdFor($event->aggregateRootUuid());
        if ($paidLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new ReturnPaidLeaveRequest(
            paidLeaveRequestId: $paidLeaveRequestId,
            returnedByUserId: $event->returnedByUserId,
            comment: $event->comment,
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $paidLeaveRequestId = $this->paidLeaveRequestIdFor($event->aggregateRootUuid());
        if ($paidLeaveRequestId === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelPaidLeaveRequest(
            paidLeaveRequestId: $paidLeaveRequestId,
            cancelledByUserId: $event->cancelledByUserId,
            isAdminAction: false,
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    /** ワークフローIDに対応する有給申請ID。有給以外・対応表に無ければnull。 */
    private function paidLeaveRequestIdFor(string $workflowRequestId): ?string
    {
        $link = $this->links->find($workflowRequestId);

        if ($link === null || $link['leave_kind'] !== LeaveRequestWorkflowLink::KIND_PAID) {
            return null;
        }

        return $link['leave_request_id'];
    }
}
