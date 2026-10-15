<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 特別休暇申請の承認(`special_leave.request_approved`)を受けて、対応する申請中のワークフローを承認する。
 *
 * まとめ申請の兄弟を休暇申請側が承認したときの逆方向の連携(論点5)。対応するワークフローは
 * 自文脈が持つ subject_type/subject_id で特定し、休暇の申請テーブルは読まない。
 * 既に承認済みなら何もしない(viaReactor=true の冪等)。有給のApproveWorkflowRequestOnPaidLeaveRequestApprovedReactorと同じ形。
 */
class ApproveWorkflowRequestOnSpecialLeaveRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST)
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
