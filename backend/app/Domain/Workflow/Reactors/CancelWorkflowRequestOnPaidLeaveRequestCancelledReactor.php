<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 有給申請の取消(`paid_leave_request.cancelled`)を受けて、対応するワークフローを取り消す。
 *
 * 申請中・差戻し中のワークフローだけを取り消す(承認済みは状態を変えない。設計原則13)。
 * viaReactor=trueのため本人チェックは行わず、既に取消済みなら何もしない(冪等)。
 * 取消の起点の操作者は休暇申請側の取消者を引き継ぐ。
 */
class CancelWorkflowRequestOnPaidLeaveRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST)
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
            reason: '有給申請が取り消されました。',
            viaReactor: true,
            initiatedByUserId: $cancelledByUserId,
        ));
    }
}
