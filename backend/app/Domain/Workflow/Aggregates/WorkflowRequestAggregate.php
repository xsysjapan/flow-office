<?php

namespace App\Domain\Workflow\Aggregates;

use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use Carbon\CarbonImmutable;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * workflow_request集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * WorkflowRequestProjectorに委ねられる。業務ルール判定(ステータス遷移の可否等)は
 * Handlerがworkflow_requests(Projection)の現在値を読んで行う
 * (docs/29-event-sourcing-framework-migration.md「Device」の節を参照。テストが
 * イベントを経由せず直接rowを作成するケースがあるため、Projectionの現在値の方が
 * 常に正しい)。
 */
class WorkflowRequestAggregate extends AggregateRoot
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function draft(
        ?int $requestTypeId,
        ?string $requestTypeCode,
        string $applicantUserId,
        string $title,
        array $formData,
        ?string $approverUserId,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): self {
        $this->recordThat(new WorkflowRequestDrafted(
            requestTypeId: $requestTypeId,
            requestTypeCode: $requestTypeCode,
            applicantUserId: $applicantUserId,
            title: $title,
            formData: $formData,
            approverUserId: $approverUserId,
            subjectType: $subjectType,
            subjectId: $subjectId,
        ));

        return $this;
    }

    public function submit(string $approverUserId, string $submittedByUserId): self
    {
        $this->recordThat(new WorkflowRequestSubmitted(
            approverUserId: $approverUserId,
            submittedByUserId: $submittedByUserId,
        ));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->recordThat(new WorkflowRequestApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new WorkflowRequestReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(string $cancelledByUserId, string $reason): self
    {
        $this->recordThat(new WorkflowRequestCancelled(cancelledByUserId: $cancelledByUserId, reason: $reason));

        return $this;
    }

    /**
     * BackfillAttendanceMonthWorkflowRequestHandler専用。WorkflowRequestによる月次勤怠申請の
     * ラップ(AttendanceMonthLockOnWorkflowRequestDraftedReactorによるカスケード)が導入される
     * 前に提出済みだった月次勤怠へ、通常と同じworkflow_request.drafted/workflow_request.submitted
     * を記録する。ただし対象のattendance_monthは既にSUBMITTED状態のため、通常の
     * persist()で発行するとAttendanceMonthLockOnWorkflowRequestDraftedReactorが反応し、
     * 既に提出済みのattendance_monthへSubmitAttendanceMonthを再ディスパッチして
     * DomainRuleExceptionが発生してしまう。そのためpersist()ではなく
     * persistWithoutApplyingToEventHandlers()でstored_eventsにのみ記録し、
     * Reactor・Projectorのどちらも一切発火させない。Projectionへの反映は呼び出し元の
     * HandlerがWorkflowRequestProjector/WorkflowRequestHistoryProjectorの該当メソッドを
     * 直接呼び出して行う(ロジックの複製を避けるため、Projector自体は変更しない)。
     */
    public function recordLegacySubmission(
        WorkflowRequestDrafted $drafted,
        WorkflowRequestSubmitted $submitted,
        CarbonImmutable $occurredAt,
    ): self {
        $this->recordThat($drafted);
        $this->recordThat($submitted);

        // recordThat()は記録時点でcreatedAt=nowを設定するため、実際に月次勤怠が
        // 提出された過去時刻へ上書きする(WorkflowRequestProjectorのsubmitted_at等に反映される)。
        $drafted->setCreatedAt($occurredAt);
        $submitted->setCreatedAt($occurredAt);

        $this->persistWithoutApplyingToEventHandlers();

        return $this;
    }
}
