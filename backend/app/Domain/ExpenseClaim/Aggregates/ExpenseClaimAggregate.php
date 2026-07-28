<?php

namespace App\Domain\ExpenseClaim\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimCancelled;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimReturned;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseItemAdded;
use App\Domain\ExpenseClaim\Events\ExpenseItemRemoved;
use App\Domain\ExpenseClaim\Events\ExpenseItemUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * expense_claim集約。明細(items)は集約内部の配列状態として持つ
 * (docs/30-usecases-expense.md)。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * ExpenseClaimProjectorに委ねられる。ステータス遷移の可否等の業務ルール判定は、他の
 * 移行済みドメイン(Workflow/BackOffice等)と同じ理由でHandlerがexpense_claims(Projection)
 * の現在値を読んで行う。この集約が保持する$itemsは「指定されたitemIdが実在する明細か」の
 * 検証にのみ使う。
 */
class ExpenseClaimAggregate extends AggregateRoot
{
    /**
     * @var array<string, true>
     */
    private array $itemIds = [];

    public function draft(string $employeeId): self
    {
        $this->recordThat(new ExpenseClaimDrafted(employeeId: $employeeId));

        return $this;
    }

    public function addItem(
        string $itemId,
        int $categoryId,
        ?string $usageDate,
        ?string $description,
        int $amount,
        ?string $projectId,
        string $evidenceType,
        ?string $factReferenceType,
        ?string $factReferenceId,
        int $commutingDeductionAmount,
    ): self {
        $this->recordThat(new ExpenseItemAdded(
            itemId: $itemId,
            categoryId: $categoryId,
            usageDate: $usageDate,
            description: $description,
            amount: $amount,
            projectId: $projectId,
            evidenceType: $evidenceType,
            factReferenceType: $factReferenceType,
            factReferenceId: $factReferenceId,
            commutingDeductionAmount: $commutingDeductionAmount,
        ));

        return $this;
    }

    public function updateItem(
        string $itemId,
        int $categoryId,
        ?string $usageDate,
        ?string $description,
        int $amount,
        ?string $projectId,
        string $evidenceType,
        ?string $factReferenceType,
        ?string $factReferenceId,
        int $commutingDeductionAmount,
    ): self {
        $this->assertItemExists($itemId);

        $this->recordThat(new ExpenseItemUpdated(
            itemId: $itemId,
            categoryId: $categoryId,
            usageDate: $usageDate,
            description: $description,
            amount: $amount,
            projectId: $projectId,
            evidenceType: $evidenceType,
            factReferenceType: $factReferenceType,
            factReferenceId: $factReferenceId,
            commutingDeductionAmount: $commutingDeductionAmount,
        ));

        return $this;
    }

    public function removeItem(string $itemId): self
    {
        $this->assertItemExists($itemId);

        $this->recordThat(new ExpenseItemRemoved(itemId: $itemId));

        return $this;
    }

    public function submit(string $approverUserId, string $submittedByUserId): self
    {
        $this->recordThat(new ExpenseClaimSubmitted(
            approverUserId: $approverUserId,
            submittedByUserId: $submittedByUserId,
        ));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->recordThat(new ExpenseClaimApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnClaim(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new ExpenseClaimReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(string $cancelledByUserId, string $reason): self
    {
        $this->recordThat(new ExpenseClaimCancelled(cancelledByUserId: $cancelledByUserId, reason: $reason));

        return $this;
    }

    public function delete(string $deletedByUserId): self
    {
        $this->recordThat(new ExpenseClaimDeleted(deletedByUserId: $deletedByUserId));

        return $this;
    }

    protected function applyExpenseItemAdded(ExpenseItemAdded $event): void
    {
        $this->itemIds[$event->itemId] = true;
    }

    protected function applyExpenseItemRemoved(ExpenseItemRemoved $event): void
    {
        unset($this->itemIds[$event->itemId]);
    }

    private function assertItemExists(string $itemId): void
    {
        if (! array_key_exists($itemId, $this->itemIds)) {
            throw new DomainRuleException("明細 [{$itemId}] はこの経費精算に存在しません。");
        }
    }
}
