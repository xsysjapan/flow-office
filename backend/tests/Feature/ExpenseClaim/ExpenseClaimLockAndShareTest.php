<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\EntityShare;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X010/UC-X011: 提出時ロック・承認者への共有、差戻し時のロック解除。
 */
class ExpenseClaimLockAndShareTest extends TestCase
{
    use RefreshDatabase;

    private function draftWithItem(User $employee, ExpenseCategory $category): string
    {
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();

        return $claimId;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
    }

    public function test_submitting_a_claim_locks_it(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->category();

        $claimId = $this->draftWithItem($employee, $category);

        $claim = ExpenseClaim::query()->findOrFail($claimId);
        $this->assertNull($claim->locked_at);
        $this->assertFalse($claim->isLocked());

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $claim->refresh();
        $this->assertNotNull($claim->locked_at);
        $this->assertNull($claim->unlocked_at);
        $this->assertTrue($claim->isLocked());
    }

    public function test_items_cannot_be_edited_added_or_removed_while_locked(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->category();

        $claimId = $this->draftWithItem($employee, $category);
        $itemId = ExpenseClaim::query()->findOrFail($claimId)->items()->first()->id;

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $this->actingAs($employee)->putJson("/api/expense-claims/{$claimId}/items/{$itemId}", [
            'category_id' => $category->id, 'amount' => 999,
        ])->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 100,
        ])->assertStatus(422);

        $this->actingAs($employee)->deleteJson("/api/expense-claims/{$claimId}/items/{$itemId}")
            ->assertStatus(422);

        $this->actingAs($employee)->patchJson("/api/expense-claims/{$claimId}/title", [
            'title' => '編集できないはず',
        ])->assertStatus(422);
    }

    public function test_returning_a_claim_unlocks_it_for_re_edit(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->category();

        $claimId = $this->draftWithItem($employee, $category);
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $this->actingAs($approver)->postJson("/api/expense-claims/{$claimId}/return", [
            'comment' => '金額が不正です',
        ])->assertOk()->assertJsonPath('status', 'returned');

        $claim = ExpenseClaim::query()->findOrFail($claimId);
        $this->assertNotNull($claim->locked_at);
        $this->assertNotNull($claim->unlocked_at);
        $this->assertFalse($claim->isLocked());

        $itemId = $claim->items()->first()->id;
        $this->actingAs($employee)->putJson("/api/expense-claims/{$claimId}/items/{$itemId}", [
            'category_id' => $category->id, 'amount' => 700,
        ])->assertOk();
    }

    public function test_submitting_a_claim_shares_it_with_the_approver(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = $this->category();

        $claimId = $this->draftWithItem($employee, $category);

        $this->assertDatabaseCount('entity_shares', 0);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $share = EntityShare::query()
            ->where('shareable_type', 'expense_claim')
            ->where('shareable_id', $claimId)
            ->first();

        $this->assertNotNull($share);
        $this->assertSame($approver->id, $share->shared_with_user_id);
        $this->assertSame($employee->id, $share->shared_by_user_id);
        $this->assertNotNull($share->shared_at);
    }
}
