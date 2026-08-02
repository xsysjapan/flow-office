<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\BackfillAttendanceMonthWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Domain\Workflow\Projectors\WorkflowRequestHistoryProjector;
use App\Domain\Workflow\Projectors\WorkflowRequestProjector;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\WorkflowRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * WorkflowRequestによる月次勤怠申請のラップ(AttendanceMonthLockOnWorkflowRequestDraftedReactor
 * によるカスケード)が導入される前に提出済みだった月次勤怠には、対応するworkflow_requestが
 * 存在しない。このHandlerは、提出済み(submitted)の月次勤怠のうち対応するworkflow_requestが
 * 欠けているものを見つけて、通常フローと同じworkflow_request.drafted/workflow_request.submitted
 * イベントで事後的に補完する(1回限りのバックフィル用途。cron常駐は前提としない)。
 *
 * ただし対象のattendance_monthは既にSUBMITTED状態のため、通常の
 * WorkflowRequestAggregate::persist()でこれらのイベントを発行するとReactor
 * (AttendanceMonthLockOnWorkflowRequestDraftedReactor)が反応してSubmitAttendanceMonthを
 * 再ディスパッチし、DomainRuleExceptionが発生する。そのため
 * WorkflowRequestAggregate::recordLegacySubmission()でReactor・Projectorのどちらも発火させずに
 * stored_eventsへ記録し、Projectionへの反映(workflow_requests / 履歴)はこのHandlerが
 * WorkflowRequestProjector/WorkflowRequestHistoryProjectorの該当メソッドを直接呼び出して行う。
 *
 * approved/closedは対象外にしている。通常のWorkflowRequestApprovedを発行すると
 * AttendanceMonthApprovalOnWorkflowRequestApprovedReactor・
 * CreateBackOfficeTaskOnApprovalReactorが反応し、既に承認済みのattendance_monthへ
 * 承認コマンドを再ディスパッチしたりバックオフィスタスクを重複生成したりしてしまうため。
 *
 * @implements CommandHandler<BackfillAttendanceMonthWorkflowRequest>
 */
class BackfillAttendanceMonthWorkflowRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly WorkflowRequestProjector $projector,
        private readonly WorkflowRequestHistoryProjector $historyProjector,
    ) {}

    /**
     * @return int バックフィル対象として処理した月次勤怠の件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof BackfillAttendanceMonthWorkflowRequest);

        $months = AttendanceMonth::query()
            ->where('status', AttendanceMonthStatus::SUBMITTED)
            ->whereNotNull('approver_user_id')
            ->whereNotNull('submitted_at')
            ->get();

        $backfilledCount = 0;

        foreach ($months as $month) {
            $hasWorkflowRequest = WorkflowRequest::query()
                ->where('subject_type', WorkflowRequestNotificationContent::ATTENDANCE_MONTH)
                ->where('subject_id', $month->id)
                ->exists();

            if ($hasWorkflowRequest) {
                continue;
            }

            $this->backfillOne($month);
            $backfilledCount++;
        }

        return $backfilledCount;
    }

    private function backfillOne(AttendanceMonth $month): void
    {
        $drafted = new WorkflowRequestDrafted(
            requestTypeId: null,
            requestTypeCode: null,
            applicantUserId: $month->user_id,
            title: "{$month->year_month} 月次勤怠",
            formData: [],
            approverUserId: $month->approver_user_id,
            subjectType: WorkflowRequestNotificationContent::ATTENDANCE_MONTH,
            subjectId: $month->id,
        );

        $submitted = new WorkflowRequestSubmitted(
            approverUserId: $month->approver_user_id,
            submittedByUserId: $month->user_id,
        );

        WorkflowRequestAggregate::retrieve((string) Str::uuid())
            ->recordLegacySubmission($drafted, $submitted, CarbonImmutable::make($month->submitted_at));

        $this->projector->onWorkflowRequestDrafted($drafted);
        $this->historyProjector->onWorkflowRequestDrafted($drafted);
        $this->projector->onWorkflowRequestSubmitted($submitted);
        $this->historyProjector->onWorkflowRequestSubmitted($submitted);
    }
}
