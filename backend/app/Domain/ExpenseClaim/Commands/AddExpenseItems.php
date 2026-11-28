<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-X004〜UC-X008: 経費明細を複数件まとめて追加する(表形式一括入力・移動経路一括入力・テンプレート適用共通)。
 * 1つでも拒否されたら全体を追加しない。入力の順に明細を追加する。
 *
 * `items`の各要素は`AddExpenseItem`と同じ項目(category_id, usage_date, description, amount, project_id,
 * evidence_type, fact_reference_type, fact_reference_id, commuting_deduction_amount, payment_bearer, attributes)に加え、
 * 任意で入口が決めた`item_id`と、保存済みの添付の参照の一覧`attachments`(`AddExpenseItem`の`attachments`と同じ形)を持てる。
 */
class AddExpenseItems implements Command
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public readonly string $claimId,
        public readonly string $addedByUserId,
        public readonly array $items,
    ) {}
}
