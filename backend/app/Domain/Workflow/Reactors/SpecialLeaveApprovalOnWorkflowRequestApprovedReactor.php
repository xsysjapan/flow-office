<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004: workflow_request(subject_type=special_leave_request)の承認を受けて、
 * 実際のSpecialLeaveRequest集約へ`ApproveSpecialLeaveRequest`を発行する。
 *
 * 期間指定でまとめて申請した複数日分(同じ`request_group_id`を持つ行)は、この
 * うち1件が承認されたタイミングで、まだ提出中の他の行もまとめて承認する
 * (PaidLeaveApprovalOnWorkflowRequestApprovedReactorと同じ考え方)。差戻しは対象外
 * (日ごとに個別差戻しする)。
 */
class SpecialLeaveApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApproveSpecialLeaveRequest(
            specialLeaveRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));

        $this->cascadeApproveGroupSiblings($workflowRequest->subject_id, $event->approvedByUserId);
    }

    /**
     * 同じ`request_group_id`を持ち、まだ提出中(submitted)の他の申請を承認する。
     * PaidLeaveApprovalOnWorkflowRequestApprovedReactorのcascadeApproveGroupSiblingsと
     * 同じ理由・同じ収束の仕方(1件ずつ再度DBを見ながら処理する)。
     */
    private function cascadeApproveGroupSiblings(?string $specialLeaveRequestId, ?string $approvedByUserId): void
    {
        $specialLeaveRequest = SpecialLeaveRequest::query()->find($specialLeaveRequestId);

        if ($specialLeaveRequest === null || $specialLeaveRequest->request_group_id === null) {
            return;
        }

        while (true) {
            $sibling = SpecialLeaveRequest::query()
                ->where('request_group_id', $specialLeaveRequest->request_group_id)
                ->where('id', '!=', $specialLeaveRequest->id)
                ->where('status', SpecialLeaveRequestStatus::SUBMITTED)
                ->orderBy('target_date')
                ->first();

            if ($sibling === null) {
                return;
            }

            $siblingWorkflowRequest = WorkflowRequest::query()
                ->where('subject_type', WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST)
                ->where('subject_id', $sibling->id)
                ->where('status', WorkflowRequestStatus::SUBMITTED)
                ->latest()
                ->first();

            if ($siblingWorkflowRequest === null) {
                // 対応するworkflow_requestが見つからない場合(通常は起こらない)は、
                // 承認待ちのまま取り残さないようSpecialLeaveRequest集約を直接承認する。
                $this->commandBus->dispatch(new ApproveSpecialLeaveRequest(
                    specialLeaveRequestId: $sibling->id,
                    approvedByUserId: $approvedByUserId,
                ));

                continue;
            }

            $this->commandBus->dispatch(new ApproveWorkflowRequest(
                workflowRequestId: $siblingWorkflowRequest->id,
                approvedByUserId: $approvedByUserId,
            ));
        }
    }
}
