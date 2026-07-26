<?php

namespace Tests\Feature\BackOffice;

use App\Models\BackOfficeTask;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UC-X012 手順5: 経費精算専用ドメイン(expense_claims)から会計/振込CSVを出力する。
 * 旧汎用ワークフロー方式(request_types.export_amount_field)は廃止したため、対象は
 * backoffice_tasks.source_type = 'ExpenseClaim' の支払予定/完了タスクのみ。
 */
class ExpensesExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_only_expense_claim_backoffice_tasks(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => '申請者太郎']);

        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31',
            'status' => 'approved', 'total_amount' => 3400,
        ]);
        ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'evidence_type' => 'fact_reference_available',
        ]);
        $expenseTask = BackOfficeTask::query()->create([
            'source_type' => 'ExpenseClaim', 'source_id' => $claim->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: 申請者太郎', 'status' => 'payment_scheduled',
        ]);

        // 別ドメイン(汎用ワークフロー: 名刺申請)のタスクは対象外であること。
        BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => (string) Str::uuid(),
            'task_type' => 'business_card', 'title' => '名刺100枚', 'status' => 'payment_scheduled',
        ]);

        $response = $this->actingAs($staff)->get('/api/exports/expenses?from=2020-01-01&to=2030-01-01');

        $response->assertSuccessful();
        $csv = $response->streamedContent();

        $this->assertStringContainsString((string) $expenseTask->id, $csv);
        $this->assertStringContainsString('3400', $csv);
        $this->assertStringNotContainsString('名刺100枚', $csv);
    }
}
