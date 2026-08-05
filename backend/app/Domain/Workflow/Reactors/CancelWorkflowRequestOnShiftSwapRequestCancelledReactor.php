<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestCancelled;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 振替休日申請の取消はShiftSwapRequest集約側で行われるため、起点となったworkflow_requestが
 * submittedのまま取り残され、統合申請一覧に承認待ちとして残り続けてしまう。ここから
 * `CancelWorkflowRequest`を発行して同期させる。
 *
 * CancelWorkflowRequestHandlerは「申請者本人のみ取消可能」を要求するため、取消操作を行った
 * 利用者(=振替休日申請の申請者)のIDをそのまま渡す。
 */
class CancelWorkflowRequestOnShiftSwapRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onShiftSwapRequestCancelled(ShiftSwapRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST)
            ->where('subject_id', $event->aggregateRootUuid())
            ->whereIn('status', WorkflowRequestStatus::cancellable())
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $event->cancelledByUserId,
            reason: '振替休日申請が取り消されました。',
        ));
    }
}
