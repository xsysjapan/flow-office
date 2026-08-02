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

        // approvedByUserIdがnullの場合、この承認はattendance_requires_approval=falseによる
        // 自動承認をApproveWorkflowRequestOnAttendanceMonthApprovedReactor経由で折り返した
        // だけであり、AttendanceMonthは既に承認済みのため下流への伝播は不要
        // (ApproveAttendanceMonthは承認者IDを必須とするコマンドでもある。
        // ExpenseClaimApprovalOnWorkflowRequestApprovedReactorと同じ理由)。
        if ($event->approvedByUserId === null) {
            return;
        }

        $this->commandBus->dispatch(new ApproveAttendanceMonth(
            $workflowRequest->subject_id,
            $event->approvedByUserId,
        ));
    }
}
