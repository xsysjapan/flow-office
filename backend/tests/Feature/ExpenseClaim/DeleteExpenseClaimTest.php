<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X010: 不要な下書きの削除。
 */
class DeleteExpenseClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_delete_their_own_draft(): void
    {
        $employee = User::factory()->create();
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->deleteJson("/api/expense-claims/{$claimId}")->assertNoContent();

        $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}")->assertNotFound();
        $list = $this->actingAs($employee)->getJson('/api/expense-claims/mine');
        $list->assertOk();
        $this->assertSame([], $list->json('data'));
    }

    public function test_deleting_a_draft_with_items_removes_the_items_too(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();

        $this->actingAs($employee)->deleteJson("/api/expense-claims/{$claimId}")->assertNoContent();

        $this->assertDatabaseMissing('expense_claims', ['id' => $claimId]);
        $this->assertDatabaseMissing('expense_items', ['claim_id' => $claimId]);
    }

    public function test_another_employee_cannot_delete_someone_elses_draft(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($other)->deleteJson("/api/expense-claims/{$claimId}")->assertForbidden();
    }

    public function test_a_submitted_claim_cannot_be_deleted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $this->actingAs($employee)->deleteJson("/api/expense-claims/{$claimId}")->assertStatus(422);
    }
}
