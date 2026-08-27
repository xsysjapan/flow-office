<?php

namespace App\Domain\Export\Services\ExpenseCsv;

use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;

/**
 * マネーフォワードクラウド経費の仕訳取込CSV形式を模した専用フォーマット。freee形式と列構成の
 * 意味は同じだが、見出し・カラム順がマネーフォワード側の取込設定に合わせたものになる。
 * カンマ区切り・UTF-8。
 */
class MoneyForwardExpenseCsvFormat implements ExpenseCsvFormat
{
    public function header(): array
    {
        return ['日付', '勘定科目', '金額', '税区分', '取引内容', '支払先名称', '証憑番号', '経費精算ID'];
    }

    public function rows(BackOfficeTask $task, ExpenseClaim $claim): array
    {
        return $claim->items->map(function (ExpenseItem $item) use ($task, $claim): array {
            $category = $item->category;

            return [
                ($item->usage_date?->format('Y/m/d')) ?? $task->created_at->toDateString(),
                $category?->account_code ?? '',
                $item->reimbursement_amount,
                $category?->tax_category ?? '',
                $item->description ?? $category?->name ?? '',
                $this->payee($item),
                $item->id,
                $claim->id,
            ];
        })->all();
    }

    public function delimiter(): string
    {
        return ',';
    }

    public function encoding(): string
    {
        return 'UTF-8';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }

    private function payee(ExpenseItem $item): string
    {
        return (string) ($item->attributes['payee'] ?? '');
    }
}
