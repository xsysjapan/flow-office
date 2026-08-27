<?php

namespace App\Domain\Export\Services\ExpenseCsv;

use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;

/**
 * freee会計の「経費精算 仕訳インポート」形式を模した専用フォーマット。明細(ExpenseItem)1件
 * につき1行を出力し、区分マスタ(expense_categories)のaccount_code・tax_categoryを
 * そのまま勘定科目・税区分として使う(未設定の場合は空欄。値の妥当性はfreee側で検証される
 * 前提とし、本フェーズでは変換・補正を行わない)。カンマ区切り・UTF-8。
 */
class FreeeExpenseCsvFormat implements ExpenseCsvFormat
{
    public function header(): array
    {
        return ['取引日', '借方勘定科目', '借方税区分', '借方金額', '摘要', '支払先', '証憑番号', 'タスクID'];
    }

    public function rows(BackOfficeTask $task, ExpenseClaim $claim): array
    {
        return $claim->items->map(function (ExpenseItem $item) use ($task): array {
            $category = $item->category;

            return [
                ($item->usage_date?->format('Y/m/d')) ?? $task->created_at->toDateString(),
                $category?->account_code ?? '',
                $category?->tax_category ?? '',
                $item->reimbursement_amount,
                $item->description ?? $category?->name ?? '',
                $this->payee($item),
                $item->id,
                $task->id,
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

    /** 支払先。専用カラムを持たないため、区分固有属性(attributes)にpayeeキーがあれば使う。 */
    private function payee(ExpenseItem $item): string
    {
        return (string) ($item->attributes['payee'] ?? '');
    }
}
