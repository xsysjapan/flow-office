<?php

namespace App\Domain\Workflow\Aggregates;

use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
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
        int $requestTypeId,
        string $requestTypeCode,
        string $applicantUserId,
        string $title,
        array $formData,
        ?string $approverUserId,
    ): self {
        $this->recordThat(new WorkflowRequestDrafted(
            requestTypeId: $requestTypeId,
            requestTypeCode: $requestTypeCode,
            applicantUserId: $applicantUserId,
            title: $title,
            formData: $formData,
            approverUserId: $approverUserId,
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

    public function approve(string $approvedByUserId): self
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
}
