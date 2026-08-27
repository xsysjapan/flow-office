<?php

namespace App\Domain\Export\Services\ExpenseApi;

use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\ExternalIntegrationConnection;

/**
 * freee会計の仕訳(取引)登録APIを模したペイロード構造。値はFreeeExpenseCsvFormatと
 * 同じ勘定科目(account_code)・税区分(tax_category)の参照方法を使う。
 * docs/30-usecases-expense.md UC-X012参照。
 */
class FreeeExpenseApiPayloadBuilder implements ExpenseApiPayloadBuilder
{
    public function key(): string
    {
        return ExternalIntegrationConnection::PROVIDER_FREEE;
    }

    public function build(ExpenseClaim $claim, string $externalEmployeeCode): array
    {
        $issueDate = $claim->approved_at ?? $claim->submitted_at;

        return [
            'employee_code' => $externalEmployeeCode,
            'expense_application_line' => [
                'source_expense_claim_id' => $claim->id,
                'title' => $claim->title,
                'issue_date' => $issueDate?->format('Y-m-d'),
                'total_amount' => $claim->total_amount,
                'details' => $claim->items->map(function (ExpenseItem $item): array {
                    $category = $item->category;

                    return [
                        'transaction_date' => $item->usage_date?->format('Y-m-d'),
                        'account_item_code' => $category?->account_code ?? '',
                        'tax_code' => $category?->tax_category ?? '',
                        'amount' => $item->reimbursement_amount,
                        'description' => $item->description ?? $category?->name ?? '',
                        'expense_item_id' => $item->id,
                    ];
                })->all(),
            ],
        ];
    }
}
