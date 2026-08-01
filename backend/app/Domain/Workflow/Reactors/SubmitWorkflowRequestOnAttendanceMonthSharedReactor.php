<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Events\AttendanceMonthShared;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A008: 月次勤怠が提出され承認者へ共有されたら、対応するworkflow_requestを提出する
 * (承認依頼の通知はSubmitWorkflowRequestHandlerが送る)。
 *
 * 下書きのworkflow_requestが無い月次勤怠(APIを通さない移行データなど)では何もしない。
 */
class SubmitWorkflowRequestOnAttendanceMonthSharedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onAttendanceMonthShared(AttendanceMonthShared $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::ATTENDANCE_MONTH)
            ->where('subject_id', $event->aggregateRootUuid())
            ->where('status', WorkflowRequestStatus::DRAFT)
            ->latest('created_at')
            ->first();

        if ($workflowRequest === null) {
            return;
        }

        $this->commandBus->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            submittedByUserId: $workflowRequest->applicant_user_id,
            approverUserId: $workflowRequest->approver_user_id,
        ));
    }
}
