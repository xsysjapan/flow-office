<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\UpdateExpenseItem;
use App\Domain\ExpenseClaim\Support\ExpenseEvidenceTypeResolver;
use App\Domain\ExpenseClaim\Support\ExpenseItemAttributesValidator;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;

/**
 * UC-X010 手順2: 経費明細を修正する。
 *
 * @implements CommandHandler<UpdateExpenseItem>
 */
class UpdateExpenseItemHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseItem
    {
        assert($command instanceof UpdateExpenseItem);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);
        $item = ExpenseItem::query()->where('claim_id', $claim->id)->findOrFail($command->itemId);

        if ($claim->employee_id !== $command->updatedByUserId) {
            throw new DomainRuleException('自分の経費精算にのみ明細を編集できます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::editable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからは明細を編集できません。');
        }

        if ($claim->isLocked()) {
            throw new DomainRuleException('この経費精算は提出によりロックされているため明細を編集できません。');
        }

        $category = ExpenseCategory::query()->where('is_active', true)->find($command->categoryId);
        if ($category === null) {
            throw new DomainRuleException('経費区分が存在しないか無効です。');
        }

        $evidenceType = ExpenseEvidenceTypeResolver::resolve($category, $command->amount, $command->evidenceType);
        $attributes = ExpenseItemAttributesValidator::validate($category, $command->attributes);
        $paymentBearer = $command->paymentBearer ?? $item->payment_bearer ?? ExpenseItem::PAYMENT_BEARER_EMPLOYEE;

        ExpenseClaimAggregate::retrieve($claim->id)
            ->updateItem(
                itemId: $item->id,
                categoryId: $category->id,
                usageDate: $command->usageDate,
                description: $command->description,
                amount: $command->amount,
                projectId: $command->projectId,
                evidenceType: $evidenceType,
                factReferenceType: $command->factReferenceType,
                factReferenceId: $command->factReferenceId,
                commutingDeductionAmount: $command->commutingDeductionAmount,
                paymentBearer: $paymentBearer,
                attributes: $attributes,
            )
            ->persist();

        return $item->refresh();
    }
}
