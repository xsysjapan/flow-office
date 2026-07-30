<?php

namespace App\Domain\ExpenseClaim\Projectors;

use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimLocked;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimShared;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimTitleUpdated;
use App\Domain\ExpenseClaim\Events\ExpenseClaimUnlocked;
use App\Domain\ExpenseClaim\Events\ExpenseItemAdded;
use App\Domain\ExpenseClaim\Events\ExpenseItemRemoved;
use App\Domain\ExpenseClaim\Events\ExpenseItemUpdated;
use App\Models\EntityShare;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * expense_claim.* イベントから expense_claims / expense_items を作成・更新する。
 * 主キーがコマンド側生成のUUIDのため、行の新規作成(drafted/item_added)自体もこの
 * Projectorが担う。total_amountは明細の(amount - commuting_deduction_amount)の合計を、
 * period_from/period_toは明細のusage_dateの最小値・最大値を、都度全件から再計算する
 * (差分更新だとリプレイで二重計上・不整合が生じるため。
 * .claude/skills/add-projection のProjector冪等性チェックリストに対応)。
 * period_from/period_toはユーザー入力欄ではなく、明細から算出される派生値
 * (docs/30-usecases-expense.md UC-X004、原則2)。
 */
class ExpenseClaimProjector extends Projector
{
    public function onExpenseClaimDrafted(ExpenseClaimDrafted $event): void
    {
        ExpenseClaim::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'employee_id' => $event->employeeId,
                'period_from' => null,
                'period_to' => null,
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
                'payment_bearer' => $event->paymentBearer,
                'reimbursement_amount' => $this->calculateReimbursement($event->amount, $event->commutingDeductionAmount, $event->paymentBearer),
                'attributes' => $event->attributes,
            ],
        );

        $this->recalculateTotal($event->aggregateRootUuid());
        $this->recalculatePeriod($event->aggregateRootUuid());
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
            'payment_bearer' => $event->paymentBearer,
            'reimbursement_amount' => $this->calculateReimbursement($event->amount, $event->commutingDeductionAmount, $event->paymentBearer),
            'attributes' => $event->attributes,
        ]);

        $this->recalculateTotal($event->aggregateRootUuid());
        $this->recalculatePeriod($event->aggregateRootUuid());
    }

    public function onExpenseItemRemoved(ExpenseItemRemoved $event): void
    {
        ExpenseItem::query()->whereKey($event->itemId)->delete();

        $this->recalculateTotal($event->aggregateRootUuid());
        $this->recalculatePeriod($event->aggregateRootUuid());
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

    /**
     * UC-X010: 提出時にclaim全体(明細含む)を編集不可にする。
     */
    public function onExpenseClaimLocked(ExpenseClaimLocked $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'locked_at' => $event->createdAt(),
        ]);
    }

    /**
     * UC-X011: 差戻し時に提出時のロックを解除する。
     */
    public function onExpenseClaimUnlocked(ExpenseClaimUnlocked $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'unlocked_at' => $event->createdAt(),
        ]);
    }

    /**
     * UC-X010: 提出時にclaim全体(明細含む)を承認者へ開示したことをentity_sharesへ記録する。
     * entity_sharesは追記専用ログ(ルートCLAUDE.md「絶対に外してはいけない設計原則」)のため、
     * リプレイ時の重複作成を避けるためexistsチェックを行う。
     */
    public function onExpenseClaimShared(ExpenseClaimShared $event): void
    {
        $alreadyShared = EntityShare::query()
            ->where('shareable_type', 'expense_claim')
            ->where('shareable_id', $event->aggregateRootUuid())
            ->where('shared_with_user_id', $event->sharedWithUserId)
            ->exists();

        if ($alreadyShared) {
            return;
        }

        EntityShare::query()->create([
            'shareable_type' => 'expense_claim',
            'shareable_id' => $event->aggregateRootUuid(),
            'shared_with_user_id' => $event->sharedWithUserId,
            'shared_by_user_id' => $event->sharedByUserId,
            'shared_at' => $event->createdAt(),
        ]);
    }

    public function onExpenseClaimTitleUpdated(ExpenseClaimTitleUpdated $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'title' => $event->title,
        ]);
    }

    public function onExpenseClaimCancelled(ExpenseClaimCancelled $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ExpenseClaimStatus::CANCELLED,
        ]);
    }

    /**
     * 不要な下書きの削除。expense_itemsはexpense_claimsへのcascadeOnDeleteで一緒に消える。
     * 再生時にdrafted→deletedを再適用しても最終状態は「行が存在しない」で変わらないため
     * 冪等。
     */
    public function onExpenseClaimDeleted(ExpenseClaimDeleted $event): void
    {
        ExpenseClaim::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    /**
     * 「経費精算機能 設計・実装指示書」6.3/6.4: 会社から社員への返金額(reimbursement_amount)。
     * 法人カード等(payment_bearer!=employee)は会社が既に直接支払っているため0円になる。
     */
    private function calculateReimbursement(int $amount, int $commutingDeductionAmount, string $paymentBearer): int
    {
        if ($paymentBearer !== ExpenseItem::PAYMENT_BEARER_EMPLOYEE) {
            return 0;
        }

        return $amount - $commutingDeductionAmount;
    }

    /**
     * total_amountは「会社から社員へ返金する総額」を表す。法人カード等で支払った明細は
     * reimbursement_amountが0なので、集計から自然に除外される。
     */
    private function recalculateTotal(string $claimId): void
    {
        $total = ExpenseItem::query()->where('claim_id', $claimId)->sum('reimbursement_amount');

        ExpenseClaim::query()->whereKey($claimId)->update(['total_amount' => $total]);
    }

    /**
     * UC-X004: period_from/period_toは明細のusage_dateの最小値・最大値から算出する
     * 表示用の派生値。明細が0件(usage_date未入力のみを含む場合も)なら両方nullにする。
     */
    private function recalculatePeriod(string $claimId): void
    {
        $usageDates = ExpenseItem::query()
            ->where('claim_id', $claimId)
            ->whereNotNull('usage_date')
            ->pluck('usage_date');

        ExpenseClaim::query()->whereKey($claimId)->update([
            'period_from' => $usageDates->min(),
            'period_to' => $usageDates->max(),
        ]);
    }
}
