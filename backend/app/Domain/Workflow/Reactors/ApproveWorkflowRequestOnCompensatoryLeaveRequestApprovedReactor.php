<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 代休申請の承認(`compensatory_leave.request_approved`)を受けて、対応する申請中のワークフローを承認する。
 *
 * まとめ申請の兄弟を休暇申請側が承認したときの逆方向の連携(論点5)。対応するワークフローは
 * 自文脈が持つ subject_type/subject_id で特定し、休暇の申請テーブルは読まない。
 * 既に承認済みなら何もしない(viaReactor=true の冪等)。
 */
class ApproveWorkflowRequestOnCompensatoryLeaveRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST)
            ->where('subject_id', $event->aggregateRootUuid())
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            approvedByUserId: $event->approvedByUserId,
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }
}
