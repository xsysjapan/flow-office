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
 * UC-P003: 特別休暇申請の取消はSpecialLeaveRequest集約側で行われるため、起点となった
 * workflow_requestがsubmittedのまま取り残され、統合申請一覧に承認待ちとして
 * 残り続けてしまう。ここから`CancelWorkflowRequest`を発行して同期させる。
 *
 * CancelWorkflowRequestHandlerは「申請者本人のみ取消可能」を要求するため、
 * 取消操作を行った利用者(=特別休暇申請の申請者)のIDをそのまま渡す。
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

        $this->commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $event->cancelledByUserId,
            reason: '特別休暇申請が取り消されました。',
        ));
    }
}
