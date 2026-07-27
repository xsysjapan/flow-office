<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\BackOfficeTask;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X004/UC-X010〜UC-X012: 申請作成→明細追加→提出→承認→バックオフィスタスク自動生成。
 */
class ExpenseClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(?int $approvalSkipThreshold = null): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
            'approval_skip_threshold' => $approvalSkipThreshold,
        ]);
    }

    public function test_draft_add_item_submit_approve_creates_backoffice_task(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->makeCategory();

        $draft = $this->actingAs($employee)->postJson('/api/expense-claims');
        $draft->assertCreated()->assertJsonPath('status', 'draft');
        $claimId = $draft->json('id');

        $item = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'description' => '自宅 → 会社(電車)',
            'amount' => 500, 'usage_date' => '2026-07-01',
        ]);
        $item->assertCreated();

        $submit = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ]);
        $submit->assertOk()->assertJsonPath('status', 'in_review');

        $approve = $this->actingAs($approver)->postJson("/api/expense-claims/{$claimId}/approve");
        $approve->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('total_amount', 500);

        $task = BackOfficeTask::query()->where('source_type', 'expense_claim')->where('source_id', $claimId)->first();
        $this->assertNotNull($task, 'バックオフィスタスクが自動生成されていること');
        $this->assertSame('expense_reimbursement', $task->task_type);
        $this->assertSame('not_started', $task->status);
    }

    public function test_bulk_add_items_creates_multiple_items_and_totals_amount(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $response = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items/bulk", [
            'items' => [
                ['category_id' => $category->id, 'amount' => 300, 'description' => '自宅 → 会社(電車)'],
                ['category_id' => $category->id, 'amount' => 700, 'description' => '会社 → 客先(電車)'],
            ],
        ]);
        $response->assertCreated();
        $this->assertCount(2, $response->json());

        $show = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $show->assertOk()->assertJsonPath('total_amount', 1000);
    }

    public function test_period_from_and_period_to_are_derived_from_item_usage_dates(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $draft = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $draft->assertOk()->assertJsonPath('period_from', null)->assertJsonPath('period_to', null);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500, 'usage_date' => '2026-07-10',
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 300, 'usage_date' => '2026-07-03',
        ])->assertCreated();

        $show = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $show->assertOk()
            ->assertJsonPath('period_from', '2026-07-03')
            ->assertJsonPath('period_to', '2026-07-10');
    }

    public function test_commuting_deduction_reduces_total_amount(): void
    {
        $employee = User::factory()->create();
        $category = $this->makeCategory();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 1000, 'commuting_deduction_amount' => 200,
        ])->assertCreated();

        $show = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $show->assertJsonPath('total_amount', 800);
    }

    public function test_auto_approves_when_all_items_are_within_the_categorys_approval_skip_threshold(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->makeCategory(approvalSkipThreshold: 1000);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();

        $submit = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ]);

        $submit->assertOk()->assertJsonPath('status', 'approved');

        $task = BackOfficeTask::query()->where('source_type', 'expense_claim')->where('source_id', $claimId)->first();
        $this->assertNotNull($task);
    }

    public function test_only_the_designated_approver_can_approve(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $stranger = User::factory()->create();
        $category = $this->makeCategory();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $this->actingAs($stranger)->postJson("/api/expense-claims/{$claimId}/approve")->assertStatus(422);
    }

    public function test_submitting_without_any_item_fails(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_submitting_a_receipt_required_item_without_an_attachment_fails(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'lodging', 'name' => '宿泊費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED,
        ]);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 8000, 'description' => '出張宿泊',
        ])->assertCreated();

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_a_stranger_cannot_add_items_to_another_employees_claim(): void
    {
        $employee = User::factory()->create();
        $stranger = User::factory()->create();
        $category = $this->makeCategory();

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($stranger)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertForbidden();
    }
}
