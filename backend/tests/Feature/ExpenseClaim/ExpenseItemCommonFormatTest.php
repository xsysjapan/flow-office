<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「経費精算機能 設計・実装指示書」6.3〜6.5: 費目にかかわらずExpenseItemを共通形式で保持する。
 * payment_bearerによるreimbursement_amountの算出と、field_definitionsによるattributesの検証。
 */
class ExpenseItemCommonFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_paid_item_is_reimbursed_in_full(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $item = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 1000,
        ]);
        $item->assertCreated()
            ->assertJsonPath('payment_bearer', 'employee')
            ->assertJsonPath('reimbursement_amount', 1000);

        $claim = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $claim->assertOk()->assertJsonPath('total_amount', 1000);
    }

    public function test_corporate_card_paid_item_has_zero_reimbursement_and_is_excluded_from_total(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $item = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 1000, 'payment_bearer' => 'corporate_card',
        ]);
        $item->assertCreated()
            ->assertJsonPath('payment_bearer', 'corporate_card')
            ->assertJsonPath('reimbursement_amount', 0);

        $claim = $this->actingAs($employee)->getJson("/api/expense-claims/{$claimId}");
        $claim->assertOk()->assertJsonPath('total_amount', 0);
    }

    public function test_attributes_are_validated_against_category_field_definitions(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
            'field_definitions' => [
                ['key' => 'origin', 'label' => '出発地', 'type' => 'text', 'required' => true],
                ['key' => 'destination', 'label' => '到着地', 'type' => 'text', 'required' => true],
            ],
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
            'attributes' => ['origin' => '名古屋', 'unknown_key' => 'x'],
        ])->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
            'attributes' => ['origin' => '名古屋'],
        ])->assertStatus(422);

        $ok = $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
            'attributes' => ['origin' => '名古屋', 'destination' => '東京'],
        ]);
        $ok->assertCreated()->assertJsonPath('attributes.origin', '名古屋');
    }

    public function test_categories_without_field_definitions_allow_any_attributes(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'other', 'name' => 'その他', 'evidence_type_default' => 'receipt_optional',
        ]);
        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
            'attributes' => ['anything' => 'goes'],
        ])->assertCreated()->assertJsonPath('attributes.anything', 'goes');
    }
}
