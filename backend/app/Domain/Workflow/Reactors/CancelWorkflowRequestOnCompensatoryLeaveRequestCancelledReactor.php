<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 代休申請の取消(`compensatory_leave.request_cancelled`)を受けて、対応するワークフローを取り消す。
 *
 * 申請中・差戻し中のワークフローだけを取り消す(承認済みは状態を変えない。設計原則13)。
 * viaReactor=trueのため本人チェックは行わず、既に取消済みなら何もしない(冪等)。
 * 特別休暇のCancelWorkflowRequestOnSpecialLeaveRequestCancelledReactorと同じ形。
 */
class CancelWorkflowRequestOnCompensatoryLeaveRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST)
            ->where('subject_id', $event->aggregateRootUuid())
            ->whereIn('status', WorkflowRequestStatus::cancellable())
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $cancelledByUserId = $event->cancelledByUserId ?? $workflowRequest->applicant_user_id;

        $this->commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $cancelledByUserId,
            reason: '代休申請が取り消されました。',
            viaReactor: true,
            initiatedByUserId: $cancelledByUserId,
        ));
    }
}
