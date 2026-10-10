<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 特別休暇申請の取消(`special_leave.request_cancelled`)を受けて、対応するワークフローを取り消す。
 *
 * 申請中・差戻し中のワークフローだけを取り消す(承認済みは状態を変えない。設計原則13)。
 * viaReactor=trueのため本人チェックは行わず、既に取消済みなら何もしない(冪等)。
 * 有給のCancelWorkflowRequestOnPaidLeaveRequestCancelledReactorと同じ形。
 */
class CancelWorkflowRequestOnSpecialLeaveRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST)
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
            reason: '特別休暇申請が取り消されました。',
            viaReactor: true,
            initiatedByUserId: $cancelledByUserId,
        ));
    }
}
