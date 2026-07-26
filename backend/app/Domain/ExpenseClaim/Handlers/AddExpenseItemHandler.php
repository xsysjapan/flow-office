<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\AddExpenseItem;
use App\Domain\ExpenseClaim\Support\ExpenseEvidenceTypeResolver;
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

        $category = ExpenseCategory::query()->where('is_active', true)->find($command->categoryId);
        if ($category === null) {
            throw new DomainRuleException('経費区分が存在しないか無効です。');
        }

        $evidenceType = ExpenseEvidenceTypeResolver::resolve($category, $command->amount, $command->evidenceType);

        $itemId = (string) Str::uuid();

        ExpenseClaimAggregate::retrieve($claim->id)
            ->addItem(
                itemId: $itemId,
                categoryId: $category->id,
                usageDate: $command->usageDate,
                origin: $command->origin,
                destination: $command->destination,
                transportType: $command->transportType,
                amount: $command->amount,
                destinationName: $command->destinationName,
                purpose: $command->purpose,
                projectId: $command->projectId,
                evidenceType: $evidenceType,
                factReferenceType: $command->factReferenceType,
                factReferenceId: $command->factReferenceId,
                commutingDeductionAmount: $command->commutingDeductionAmount,
            )
            ->persist();

        return ExpenseItem::query()->findOrFail($itemId);
    }
}
