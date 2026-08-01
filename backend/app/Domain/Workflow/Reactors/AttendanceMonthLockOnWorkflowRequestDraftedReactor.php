<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\AttendanceMonth;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-A008: 月次勤怠申請のworkflow_requestが下書き作成されたら、対象のattendance_month集約を
 * 実際に提出(= 提出・ロック・承認者への共有イベントの記録)する。
 *
 * 月次勤怠申請は「workflow_requestの下書き作成」を起点とするオーケストレーション型で、
 * ここから SubmitAttendanceMonth → attendance_month.shared →
 * SubmitWorkflowRequestOnAttendanceMonthSharedReactor → SubmitWorkflowRequest と
 * カスケードする(ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
 */
class AttendanceMonthLockOnWorkflowRequestDraftedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        if ($event->subjectType !== WorkflowRequestNotificationContent::ATTENDANCE_MONTH) {
            return;
        }

        $workflowRequest = WorkflowRequest::query()->find($event->aggregateRootUuid());

        if ($workflowRequest === null
            || $workflowRequest->subject_id === null
            || $workflowRequest->approver_user_id === null) {
            return;
        }

        // 初回提出ではattendance_monthsの行がまだ存在しない(集約IDだけがsubject_idとして
        // 先に確定している)ため、その場合はworkflow_requestsの値から復元する。
        $month = AttendanceMonth::query()->find($workflowRequest->subject_id);

        $yearMonth = $month?->year_month ?? $this->yearMonthFromTitle($workflowRequest->title);

        if ($yearMonth === null) {
            return;
        }

        $this->commandBus->dispatch(new SubmitAttendanceMonth(
            userId: $month?->user_id ?? $workflowRequest->applicant_user_id,
            yearMonth: $yearMonth,
            approverUserId: $workflowRequest->approver_user_id,
            attendanceMonthId: $workflowRequest->subject_id,
        ));
    }

    /** タイトルは AttendanceController が "{yearMonth} 月次勤怠" 形式で組み立てている。 */
    private function yearMonthFromTitle(string $title): ?string
    {
        return preg_match('/\d{4}-\d{2}/', $title, $matches) === 1 ? $matches[0] : null;
    }
}
