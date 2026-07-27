<?php

namespace App\Domain\ExpenseClaim\Projectors;

use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseItemAdded;
use App\Domain\ExpenseClaim\Events\ExpenseItemRemoved;
use App\Domain\ExpenseClaim\Events\ExpenseItemUpdated;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * expense_claim.* イベントから expense_claims / expense_items を作成・更新する。
 * 主キーがコマンド側生成のUUIDのため、行の新規作成(drafted/item_added)自体もこの
 * Projectorが担う。total_amountは明細の(amount - commuting_deduction_amount)の合計を
 * 都度全件から再計算する(差分更新だとリプレイで二重計上されるため。
 * .claude/skills/add-projection のProjector冪等性チェックリストに対応)。
 */
class ExpenseClaimProjector extends Projector
{
    public function onExpenseClaimDrafted(ExpenseClaimDrafted $event): void
    {
        ExpenseClaim::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'employee_id' => $event->employeeId,
                'period_from' => $event->periodFrom,
                'period_to' => $event->periodTo,
                'status' => ExpenseClaimStatus::DRAFT,
                'total_amount' => 0,
            ],
        );
    }

    public function onExpenseItemAdded(ExpenseItemAdded $event): void
    {
        ExpenseItem::query()->updateOrCreate(
            ['id' => $event->itemId],
            [
                'claim_id' => $event->aggregateRootUuid(),
                'category_id' => $event->categoryId,
                'usage_date' => $event->usageDate,
                'description' => $event->description,
                'amount' => $event->amount,
                'project_id' => $event->projectId,
                'evidence_type' => $event->evidenceType,
                'fact_reference_type' => $event->factReferenceType,
                'fact_reference_id' => $event->factReferenceId,
                'commuting_deduction_amount' => $event->commutingDeductionAmount,
            ],
        );

        $this->recalculateTotal($event->aggregateRootUuid());
    }

    public function onExpenseItemUpdated(ExpenseItemUpdated $event): void
    {
        ExpenseItem::query()->whereKey($event->itemId)->update([
            'category_id' => $event->categoryId,
            'usage_date' => $event->usageDate,
            'description' => $event->description,
            'amount' => $event->amount,
            'project_id' => $event->projectId,
            'evidence_type' => $event->evidenceType,
            'fact_reference_type' => $event->factReferenceType,
            'fact_reference_id' => $event->factReferenceId,
            'commuting_deduction_amount' => $event->commutingDeductionAmount,
        ]);

        $this->recalculateTotal($event->aggregateRootUuid());
    }

    public function onExpenseItemRemoved(ExpenseItemRemoved $event): void
    {
        ExpenseItem::query()->whereKey($event->itemId)->delete();

        $this->recalculateTotal($event->aggregateRootUuid());
    }

    public function onExpenseClaimSubmitted(ExpenseClaimSubmitted $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'approver_user_id' => $event->approverUserId,
            'status' => ExpenseClaimStatus::IN_REVIEW,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimApproved(ExpenseClaimApproved $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimReturned(ExpenseClaimReturned $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::RETURNED,
        ]);
    }

    public function onExpenseClaimCancelled(ExpenseClaimCancelled $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::CANCELLED,
        ]);
    }

    private function recalculateTotal(string $claimId): void
    {
        $total = ExpenseItem::query()->where('claim_id', $claimId)->get()
            ->sum(fn (ExpenseItem $item) => $item->amount - $item->commuting_deduction_amount);

        ExpenseClaim::query()->whereKey($claimId)->update(['total_amount' => $total]);
    }
}
