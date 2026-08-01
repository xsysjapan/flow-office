<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X011 手順3 / 取消: 差戻し→再編集→再提出、取消。
 */
class ExpenseClaimReturnAndCancelTest extends TestCase
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

    public function test_returned_claim_can_be_edited_and_resubmitted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->draftWithItem($employee, $category);
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $return = $this->actingAs($approver)->postJson("/api/expense-claims/{$claimId}/return", [
            'comment' => '金額が不正です',
        ]);
        $return->assertOk()->assertJsonPath('status', 'returned');

        $item = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 300,
        ]);
        $item->assertCreated();

        $resubmit = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ]);
        $resubmit->assertOk()->assertJsonPath('status', 'in_review');

        $history = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}/history");
        $history->assertOk();
        $actions = collect($history->json())->pluck('action');
        $this->assertSame(['drafted', 'submitted', 'returned', 'submitted'], $actions->all());
    }

    public function test_draft_claim_can_be_cancelled_by_its_owner(): void
    {
        $employee = User::factory()->create();
        $stranger = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->draftWithItem($employee, $category);

        $this->actingAs($stranger)->postJson("/api/expense-claims/{$claimId}/cancel", [
            'reason' => '不要になった',
        ])->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/cancel", [
            'reason' => '不要になった',
        ])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_cancelling_a_submitted_claim_also_cancels_the_workflow_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->draftWithItem($employee, $category);
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/cancel", [
            'reason' => '不要になった',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $claimId)->firstOrFail();
        $this->assertSame('cancelled', $workflowRequest->status);
    }

    public function test_approved_claim_cannot_be_cancelled(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->draftWithItem($employee, $category);
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();
        $this->actingAs($approver)->postJson("/api/expense-claims/{$claimId}/approve")->assertOk();

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/cancel", [
            'reason' => '取消したい',
        ])->assertStatus(422);
    }
}
