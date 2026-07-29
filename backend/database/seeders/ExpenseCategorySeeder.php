<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * UC-X001: 経費区分マスタの初期データ(docs/30-usecases-expense.md)。
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'code' => 'transportation',
                'name' => '交通費',
                'description' => '通勤費・業務交通費',
                'entry_mode' => ExpenseCategory::ENTRY_MODE_BATCH,
                'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
                'receipt_required_threshold' => null,
                'approval_skip_threshold' => 3000,
            ],
            [
                'code' => 'lodging',
                'name' => '宿泊費',
                'description' => '出張時の宿泊費',
                'entry_mode' => ExpenseCategory::ENTRY_MODE_SINGLE,
                'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED,
                'receipt_required_threshold' => null,
                'approval_skip_threshold' => null,
            ],
            [
                'code' => 'meal',
                'name' => '会食',
                'description' => '接待・会議に伴う飲食費',
                'entry_mode' => ExpenseCategory::ENTRY_MODE_SINGLE,
                'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED,
                'receipt_required_threshold' => null,
                'approval_skip_threshold' => null,
            ],
            [
                'code' => 'supplies',
                'name' => '消耗品',
                'description' => '業務用消耗品の購入費',
                'entry_mode' => ExpenseCategory::ENTRY_MODE_SINGLE,
                'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_OPTIONAL,
                'receipt_required_threshold' => 3000,
                'approval_skip_threshold' => 1000,
            ],
            [
                'code' => 'other',
                'name' => 'その他',
                'description' => '上記以外の経費',
                'entry_mode' => ExpenseCategory::ENTRY_MODE_SINGLE,
                'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_OPTIONAL,
                'receipt_required_threshold' => 3000,
                'approval_skip_threshold' => null,
            ],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::query()->firstOrCreate(['code' => $category['code']], $category);
        }
    }
}
