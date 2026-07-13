<?php

namespace Tests\Feature;

use App\Models\BackOfficeTask;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-B004 手順5: 会計/振込CSVを出力する。対象はハードコードされたtask_typeではなく、
 * request_types.export_amount_field が設定された申請種別かどうかで決まる。
 */
class ExpensesExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_only_request_types_configured_with_an_export_amount_field(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => '申請者太郎']);

        $expenseType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算',
            'form_schema' => [], 'is_active' => true,
            'requires_backoffice_task' => true, 'backoffice_task_type' => 'expense_reimbursement',
            'export_amount_field' => 'amount',
        ]);
        $businessCardType = RequestType::query()->create([
            'code' => 'business_card', 'name' => '名刺申請',
            'form_schema' => [], 'is_active' => true,
            'requires_backoffice_task' => true, 'backoffice_task_type' => 'business_card',
        ]);

        $expenseRequest = WorkflowRequest::query()->create([
            'request_type_id' => $expenseType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'status' => 'approved', 'form_data' => ['amount' => 3400],
        ]);
        $expenseTask = BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => $expenseRequest->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: タクシー代', 'status' => 'payment_scheduled',
        ]);

        $businessCardRequest = WorkflowRequest::query()->create([
            'request_type_id' => $businessCardType->id, 'title' => '名刺100枚', 'applicant_user_id' => $applicant->id,
            'status' => 'approved', 'form_data' => ['quantity' => 100],
        ]);
        BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => $businessCardRequest->id,
            'task_type' => 'business_card', 'title' => '名刺申請: 名刺100枚', 'status' => 'payment_scheduled',
        ]);

        $response = $this->actingAs($staff)->get('/api/exports/expenses?from=2020-01-01&to=2030-01-01');

        $response->assertSuccessful();
        $csv = $response->streamedContent();

        $this->assertStringContainsString((string) $expenseTask->id, $csv);
        $this->assertStringContainsString('3400', $csv);
        $this->assertStringNotContainsString('名刺100枚', $csv);
    }
}
