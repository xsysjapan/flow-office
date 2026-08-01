<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A009: 月次勤怠申請のworkflow_requestが承認されたら、対象のattendance_month集約も承認する。
 * 承認通知はApproveWorkflowRequestHandler側で送られる。
 */
class AttendanceMonthApprovalOnWorkflowRequestApprovedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::ATTENDANCE_MONTH
            || $workflowRequest->subject_id === null) {
            return;
        }

        $this->commandBus->dispatch(new ApproveAttendanceMonth(
            $workflowRequest->subject_id,
            $event->approvedByUserId,
        ));
    }
}
