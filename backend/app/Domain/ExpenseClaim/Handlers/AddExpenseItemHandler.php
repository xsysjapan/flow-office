<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\AddExpenseItem;
use App\Domain\ExpenseClaim\Support\ExpenseEvidenceTypeResolver;
use App\Domain\ExpenseClaim\Support\ExpenseItemAttributesValidator;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;
use Illuminate\Support\Str;

/**
 * UC-X004〜UC-X008: 経費明細を1件追加する。
 *
 * @implements CommandHandler<AddExpenseItem>
 */
class AddExpenseItemHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseItem
    {
        assert($command instanceof AddExpenseItem);

        $claim = ExpenseClaim::query()->findOrFail($command->claimId);

        if ($claim->employee_id !== $command->addedByUserId) {
            throw new DomainRuleException('自分の経費精算にのみ明細を追加できます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::editable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからは明細を追加できません。');
        }

        if ($claim->isLocked()) {
            throw new DomainRuleException('この経費精算は提出によりロックされているため明細を追加できません。');
        }

        $category = ExpenseCategory::query()->where('is_active', true)->find($command->categoryId);
        if ($category === null) {
            throw new DomainRuleException('経費区分が存在しないか無効です。');
        }

        $evidenceType = ExpenseEvidenceTypeResolver::resolve($category, $command->amount, $command->evidenceType);
        $attributes = ExpenseItemAttributesValidator::validate($category, $command->attributes);
        $paymentBearer = $command->paymentBearer ?? ExpenseItem::PAYMENT_BEARER_EMPLOYEE;

        $itemId = (string) Str::uuid();

        ExpenseClaimAggregate::retrieve($claim->id)
            ->addItem(
                itemId: $itemId,
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

        return ExpenseItem::query()->findOrFail($itemId);
    }
}
