<?php

namespace App\Domain\Workflow\Projectors;

use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Models\WorkflowRequestHistoryAction;
use App\Models\WorkflowRequestHistoryEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * workflow_request.* イベントから workflow_request_history_entries を作成する。
 * イベントクラス名・payload形状に依存しない安定した表示用の履歴を持たせるため、
 * WorkflowRequestControllerの/history はこのProjectionだけを参照する
 * (stored_eventsを直接参照しない。docs/29-event-sourcing-framework-migration.md参照)。
 */
class WorkflowRequestHistoryProjector extends Projector
{
    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        $this->record($event, WorkflowRequestHistoryAction::DRAFTED, $event->applicantUserId);
    }

    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        $this->record($event, WorkflowRequestHistoryAction::SUBMITTED, $event->submittedByUserId);
    }

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $this->record($event, WorkflowRequestHistoryAction::APPROVED, $event->approvedByUserId);
    }

    public function onWorkflowRequestReturned(WorkflowRequestReturned $event): void
    {
        $this->record($event, WorkflowRequestHistoryAction::RETURNED, $event->returnedByUserId, $event->comment);
    }

    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $this->record($event, WorkflowRequestHistoryAction::CANCELLED, $event->cancelledByUserId, $event->reason);
    }

    private function record(ShouldBeStored $event, string $action, ?int $actorUserId, ?string $comment = null): void
    {
        WorkflowRequestHistoryEntry::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'workflow_request_id' => $event->aggregateRootUuid(),
                'action' => $action,
                'actor_user_id' => $actorUserId,
                'comment' => $comment,
                'occurred_at' => $event->createdAt(),
            ],
        );
    }
}
