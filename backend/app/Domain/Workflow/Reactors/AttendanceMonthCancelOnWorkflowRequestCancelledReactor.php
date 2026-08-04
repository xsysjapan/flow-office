<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Commands\CancelSubmittedAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A010関連: 月次勤怠申請のworkflow_requestが申請者自身によって取り消されたら、対象の
 * attendance_month集約も未提出へ戻す。これが無いと、提出済み・差戻し済みの月次勤怠申請を
 * 取り消した際にattendance_monthsの行だけが提出済み/差戻し済みのまま取り残され、再提出も
 * できなくなる。
 */
class AttendanceMonthCancelOnWorkflowRequestCancelledReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest?->subject_type !== WorkflowRequestNotificationContent::ATTENDANCE_MONTH
            || $workflowRequest->subject_id === null) {
            return;
        }

        $this->commandBus->dispatch(new CancelSubmittedAttendanceMonth(
            $workflowRequest->subject_id,
            $event->cancelledByUserId,
        ));
    }
}
