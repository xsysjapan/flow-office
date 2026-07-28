<?php

namespace App\Domain\ExpenseClaim\Projectors;

use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Models\ExpenseClaimHistoryAction;
use App\Models\ExpenseClaimHistoryEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * expense_claim.* イベントから expense_claim_history_entries を作成する。
 * WorkflowRequestHistoryProjectorと同じ考え方で、stored_eventsの生イベントを
 * UIに直接公開しない(docs/29-event-sourcing-framework-migration.md参照)。
 */
class ExpenseClaimHistoryProjector extends Projector
{
    public function onExpenseClaimDrafted(ExpenseClaimDrafted $event): void
    {
        $this->record($event, ExpenseClaimHistoryAction::DRAFTED, $event->employeeId);
    }

    public function onExpenseClaimSubmitted(ExpenseClaimSubmitted $event): void
    {
        $this->record($event, ExpenseClaimHistoryAction::SUBMITTED, $event->submittedByUserId);
    }

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        $this->record($event, ExpenseClaimHistoryAction::APPROVED, $event->approvedByUserId);
    }

    public function onExpenseClaimReturned(ExpenseClaimReturned $event): void
    {
        $this->record($event, ExpenseClaimHistoryAction::RETURNED, $event->returnedByUserId, $event->comment);
    }

    public function onExpenseClaimCancelled(ExpenseClaimCancelled $event): void
    {
        $this->record($event, ExpenseClaimHistoryAction::CANCELLED, $event->cancelledByUserId, $event->reason);
    }

    /**
     * 削除された下書きの履歴は表示先(経費精算自体)が無くなるため、残さず一緒に削除する。
     */
    public function onExpenseClaimDeleted(ExpenseClaimDeleted $event): void
    {
        ExpenseClaimHistoryEntry::query()->where('expense_claim_id', $event->aggregateRootUuid())->delete();
    }

    private function record(ShouldBeStored $event, string $action, ?string $actorUserId, ?string $comment = null): void
    {
        ExpenseClaimHistoryEntry::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'expense_claim_id' => $event->aggregateRootUuid(),
                'action' => $action,
                'actor_user_id' => $actorUserId,
                'comment' => $comment,
                'occurred_at' => $event->createdAt(),
            ],
        );
    }
}
