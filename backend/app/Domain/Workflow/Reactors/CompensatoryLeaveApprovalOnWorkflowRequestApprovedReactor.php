<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-P004相当: workflow_request(subject_type=compensatory_leave_request)の承認を受けて、
 * 実際のCompensatoryLeaveRequest集約へ`ApproveCompensatoryLeaveRequest`を発行する。
 *
 * 期間指定でまとめて申請した複数日分(同じ`request_group_id`を持つ行)は、この
 * うち1件が承認されたタイミングで、まだ提出中の他の行もまとめて承認する
 * (PaidLeaveApprovalOnWorkflowRequestApprovedReactorと同じ考え方。承認者が期間全体を
 * 1回の操作で承認できるようにするため)。差戻しは対象外(日ごとに個別差戻しする)。
 */
class CompensatoryLeaveApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST) {
            return;
        }

        $this->commandBus->dispatch(new ApproveCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $workflowRequest->subject_id,
            approvedByUserId: $event->approvedByUserId,
        ));

        $this->cascadeApproveGroupSiblings($workflowRequest->subject_id, $event->approvedByUserId);
    }

    /**
     * 同じ`request_group_id`を持ち、まだ提出中(submitted)の他の申請を承認する。
     * 1件ずつ再度DBを見ながら処理することで、この承認が
     * `ApproveWorkflowRequest`→(このReactorの再帰呼び出し)経由でさらに他の兄弟を
     * 承認済みにしていても、二重承認によるエラーが起きないようにする。
     */
    private function cascadeApproveGroupSiblings(?string $compensatoryLeaveRequestId, ?string $approvedByUserId): void
    {
        $compensatoryLeaveRequest = CompensatoryLeaveRequest::query()->find($compensatoryLeaveRequestId);

        if ($compensatoryLeaveRequest === null || $compensatoryLeaveRequest->request_group_id === null) {
            return;
        }

        while (true) {
            $sibling = CompensatoryLeaveRequest::query()
                ->where('request_group_id', $compensatoryLeaveRequest->request_group_id)
                ->where('id', '!=', $compensatoryLeaveRequest->id)
                ->where('status', CompensatoryLeaveRequestStatus::SUBMITTED)
                ->orderBy('target_date')
                ->first();

            if ($sibling === null) {
                return;
            }

            $siblingWorkflowRequest = WorkflowRequest::query()
                ->where('subject_type', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST)
                ->where('subject_id', $sibling->id)
                ->where('status', WorkflowRequestStatus::SUBMITTED)
                ->latest()
                ->first();

            if ($siblingWorkflowRequest === null) {
                // 対応するworkflow_requestが見つからない場合(通常は起こらない)は、
                // 承認待ちのまま取り残さないようCompensatoryLeaveRequest集約を直接承認する。
                $this->commandBus->dispatch(new ApproveCompensatoryLeaveRequest(
                    compensatoryLeaveRequestId: $sibling->id,
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
