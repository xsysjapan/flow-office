<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\ExpenseCategory;
use App\Models\ExpenseEntryPreset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X001: 経費区分マスタと入力プリセットのCRUDと権限。
 */
class ExpenseMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_write_expense_categories(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();

        $payload = ['code' => 'transportation', 'name' => '交通費', 'entry_mode' => 'batch'];

        $this->actingAs($employee)->postJson('/api/admin/expense-categories', $payload)->assertForbidden();

        $created = $this->actingAs($admin)->postJson('/api/admin/expense-categories', $payload);
        $created->assertCreated()->assertJsonPath('entry_mode', 'batch');

        $categoryId = $created->json('id');
        $this->actingAs($admin)->putJson("/api/admin/expense-categories/{$categoryId}", [
            'code' => 'transportation', 'name' => '交通費(更新)', 'entry_mode' => 'batch',
        ])->assertOk()->assertJsonPath('name', '交通費(更新)');

        $this->actingAs($employee)->getJson('/api/expense-categories')->assertOk()->assertJsonCount(1);
    }

    public function test_expense_category_rejects_invalid_entry_mode(): void
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->postJson('/api/admin/expense-categories', [
            'code' => 'meal', 'name' => '会食', 'entry_mode' => 'weekly',
        ])->assertStatus(422)->assertJsonValidationErrors('entry_mode');

        $created = $this->actingAs($admin)->postJson('/api/admin/expense-categories', [
            'code' => 'meal', 'name' => '会食', 'entry_mode' => 'single',
        ]);
        $created->assertCreated();

        $categoryId = $created->json('id');
        $this->actingAs($admin)->putJson("/api/admin/expense-categories/{$categoryId}", [
            'code' => 'meal', 'name' => '会食', 'entry_mode' => 'weekly',
        ])->assertStatus(422)->assertJsonValidationErrors('entry_mode');
    }

    public function test_personal_preset_is_editable_only_by_its_owner(): void
    {
        $employee = User::factory()->create();
        $stranger = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        $created = $this->actingAs($employee)->postJson('/api/expense-entry-presets', [
            'visibility' => 'personal', 'name' => '自宅⇔会社', 'preset_type' => 'single_item',
            'definition' => [['category_id' => $category->id, 'description' => '自宅 → 会社(電車)', 'amount' => 300]],
        ]);
        $created->assertCreated();
        $presetId = $created->json('id');

        $this->actingAs($stranger)->putJson("/api/expense-entry-presets/{$presetId}", [
            'name' => '改ざん', 'preset_type' => 'single_item',
            'definition' => [['category_id' => $category->id, 'amount' => 999]],
        ])->assertForbidden();

        $this->actingAs($employee)->putJson("/api/expense-entry-presets/{$presetId}", [
            'name' => '自宅⇔会社(更新)', 'preset_type' => 'single_item',
            'definition' => [['category_id' => $category->id, 'description' => '自宅 → 会社(電車)', 'amount' => 320]],
        ])->assertOk()->assertJsonPath('name', '自宅⇔会社(更新)');
    }

    public function test_company_preset_requires_accounting_or_admin_role(): void
    {
        $employee = User::factory()->create();
        $accountingStaff = User::factory()->create();
        $this->assignRole($accountingStaff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        $this->actingAs($employee)->postJson('/api/expense-entry-presets', [
            'visibility' => 'company', 'name' => '本社⇔A社', 'preset_type' => 'single_item',
            'definition' => [['category_id' => $category->id, 'description' => '本社 → A社(電車)', 'amount' => 400]],
        ])->assertForbidden();

        $created = $this->actingAs($accountingStaff)->postJson('/api/expense-entry-presets', [
            'visibility' => 'company', 'name' => '本社⇔A社', 'preset_type' => 'single_item',
            'definition' => [['category_id' => $category->id, 'description' => '本社 → A社(電車)', 'amount' => 400]],
        ]);
        $created->assertCreated();

        // 全社共有プリセットは全社員の候補一覧に表示される。
        $index = $this->actingAs($employee)->getJson('/api/expense-entry-presets');
        $index->assertOk()->assertJsonCount(1);
    }

    public function test_presets_index_merges_own_personal_and_all_company_and_system_presets(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $accountingStaff = User::factory()->create();
        $this->assignRole($accountingStaff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        ExpenseEntryPreset::query()->create([
            'visibility' => 'personal', 'owner_user_id' => $employee->id, 'name' => '自分のプリセット',
            'preset_type' => 'single_item', 'definition' => [['category_id' => $category->id, 'amount' => 100]],
            'created_by' => $employee->id,
        ]);
        ExpenseEntryPreset::query()->create([
            'visibility' => 'personal', 'owner_user_id' => $otherEmployee->id, 'name' => '他人のプリセット',
            'preset_type' => 'single_item', 'definition' => [['category_id' => $category->id, 'amount' => 200]],
            'created_by' => $otherEmployee->id,
        ]);
        ExpenseEntryPreset::query()->create([
            'visibility' => 'company', 'name' => '全社共有プリセット',
            'preset_type' => 'single_item', 'definition' => [['category_id' => $category->id, 'amount' => 300]],
            'created_by' => $accountingStaff->id,
        ]);
        ExpenseEntryPreset::query()->create([
            'visibility' => 'system', 'name' => 'システム標準プリセット',
            'preset_type' => 'single_item', 'definition' => [['category_id' => $category->id, 'amount' => 400]],
        ]);

        $response = $this->actingAs($employee)->getJson('/api/expense-entry-presets');
        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertContains('自分のプリセット', $names);
        $this->assertContains('全社共有プリセット', $names);
        $this->assertContains('システム標準プリセット', $names);
        $this->assertNotContains('他人のプリセット', $names);
    }

    public function test_applying_a_preset_increments_usage_count(): void
    {
        $employee = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        $preset = ExpenseEntryPreset::query()->create([
            'visibility' => 'personal', 'owner_user_id' => $employee->id, 'name' => '自宅⇔会社',
            'preset_type' => 'single_item', 'definition' => [['category_id' => $category->id, 'amount' => 300]],
            'created_by' => $employee->id,
        ]);

        $this->actingAs($employee)->postJson("/api/expense-entry-presets/{$preset->id}/apply")
            ->assertOk()->assertJsonPath('usage_count', 1);

        $this->assertNotNull($preset->refresh()->last_used_at);
    }
}
