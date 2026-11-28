<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\AddExpenseItems;
use App\Domain\ExpenseClaim\Support\ExpenseEvidenceTypeResolver;
use App\Domain\ExpenseClaim\Support\ExpenseItemAttributesValidator;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExpenseItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UC-X004〜UC-X008: 経費明細を複数件まとめて追加する。明細ごとの検査は`AddExpenseItemHandler`と同じ。
 * 明細の拒否は`items.{i}.{項目}`のキーで返す(画面が行の下に出す)。1つでも拒否なら何も記録しない。
 *
 * @implements CommandHandler<AddExpenseItems>
 */
class AddExpenseItemsHandler implements CommandHandler
{
    /**
     * @return Collection<int, ExpenseItem> 入力の順の明細
     */
    public function handle(Command $command): Collection
    {
        assert($command instanceof AddExpenseItems);

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

        $aggregate = ExpenseClaimAggregate::retrieve($claim->id);
        $itemIds = [];

        foreach (array_values($command->items) as $index => $input) {
            $category = ExpenseCategory::query()->where('is_active', true)->find($input['category_id']);
            if ($category === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.category_id" => '経費区分が存在しないか無効です。',
                ]);
            }

            $amount = (int) $input['amount'];
            $evidenceType = ExpenseEvidenceTypeResolver::resolve($category, $amount, $input['evidence_type'] ?? null);

            try {
                $attributes = ExpenseItemAttributesValidator::validate($category, $input['attributes'] ?? null);
            } catch (DomainRuleException $e) {
                throw ValidationException::withMessages(["items.{$index}.attributes" => $e->getMessage()]);
            }

            $itemId = (string) Str::uuid();
            $itemIds[] = $itemId;

            $aggregate->addItem(
                itemId: $itemId,
                categoryId: $category->id,
                usageDate: $input['usage_date'] ?? null,
                description: $input['description'] ?? null,
                amount: $amount,
                projectId: $input['project_id'] ?? null,
                evidenceType: $evidenceType,
                factReferenceType: $input['fact_reference_type'] ?? null,
                factReferenceId: $input['fact_reference_id'] ?? null,
                commutingDeductionAmount: $input['commuting_deduction_amount'] ?? 0,
                paymentBearer: $input['payment_bearer'] ?? ExpenseItem::PAYMENT_BEARER_EMPLOYEE,
                attributes: $attributes,
            );
        }

        $aggregate->persist();

        return ExpenseItem::query()->whereIn('id', $itemIds)->get()
            ->sortBy(fn (ExpenseItem $item) => array_search($item->id, $itemIds, true))
            ->values();
    }
}
