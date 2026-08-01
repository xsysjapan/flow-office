<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A010: 月次勤怠申請のworkflow_requestが差戻されたら、対象のattendance_month集約も
 * 差戻す(提出時のロックもここで解除される)。差戻し通知は
 * ReturnWorkflowRequestHandler側で送られる。
 */
class AttendanceMonthReturnOnWorkflowRequestReturnedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::ATTENDANCE_MONTH
            || $workflowRequest->subject_id === null) {
            return;
        }

        $this->commandBus->dispatch(new ReturnAttendanceMonth(
            $workflowRequest->subject_id,
            $event->returnedByUserId,
            $event->comment,
        ));
    }
}
