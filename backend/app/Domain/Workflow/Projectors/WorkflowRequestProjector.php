<?php

namespace App\Domain\Workflow\Projectors;

use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * workflow_request.* イベントから workflow_requests を作成・更新する。主キーがコマンド側
 * 生成のUUIDのため、行の新規作成(drafted)自体もこのProjectorが担う。
 */
class WorkflowRequestProjector extends Projector
{
    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        WorkflowRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'request_type_id' => $event->requestTypeId,
                'title' => $event->title,
                'applicant_user_id' => $event->applicantUserId,
                'approver_user_id' => $event->approverUserId,
                'status' => WorkflowRequestStatus::DRAFT,
                'form_data' => $event->formData,
            ],
        );
    }

    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        WorkflowRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'approver_user_id' => $event->approverUserId,
            'status' => WorkflowRequestStatus::SUBMITTED,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        WorkflowRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        WorkflowRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        WorkflowRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => WorkflowRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
