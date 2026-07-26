<?php

namespace Tests\Feature\ExpenseClaim;

use App\Models\ExpenseCategory;
use App\Models\ExpenseRouteTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-X001〜UC-X003: 経費区分・移動区間テンプレートマスタのCRUDと権限。
 */
class ExpenseMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_write_expense_categories(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();

        $payload = ['code' => 'transportation', 'name' => '交通費'];

        $this->actingAs($employee)->postJson('/api/expense-categories', $payload)->assertForbidden();

        $created = $this->actingAs($admin)->postJson('/api/expense-categories', $payload);
        $created->assertCreated();

        $categoryId = $created->json('id');
        $this->actingAs($admin)->putJson("/api/expense-categories/{$categoryId}", [
            'code' => 'transportation', 'name' => '交通費(更新)',
        ])->assertOk()->assertJsonPath('name', '交通費(更新)');

        $this->actingAs($employee)->getJson('/api/expense-categories')->assertOk()->assertJsonCount(1);
    }

    public function test_personal_route_template_is_editable_only_by_its_owner(): void
    {
        $employee = User::factory()->create();
        $stranger = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        $created = $this->actingAs($employee)->postJson('/api/expense-route-templates', [
            'scope' => 'personal', 'name' => '自宅⇔会社', 'origin' => '自宅', 'destination' => '会社',
            'transport_type' => 'train', 'amount' => 300, 'category_id' => $category->id,
        ]);
        $created->assertCreated();
        $templateId = $created->json('id');

        $this->actingAs($stranger)->putJson("/api/expense-route-templates/{$templateId}", [
            'name' => '改ざん', 'origin' => '自宅', 'destination' => '会社',
            'transport_type' => 'train', 'amount' => 999, 'category_id' => $category->id,
        ])->assertForbidden();

        $this->actingAs($employee)->putJson("/api/expense-route-templates/{$templateId}", [
            'name' => '自宅⇔会社(更新)', 'origin' => '自宅', 'destination' => '会社',
            'transport_type' => 'train', 'amount' => 320, 'category_id' => $category->id,
        ])->assertOk()->assertJsonPath('amount', 320);
    }

    public function test_company_route_template_requires_accounting_or_admin_role(): void
    {
        $employee = User::factory()->create();
        $accountingStaff = User::factory()->create();
        $accountingStaff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        $this->actingAs($employee)->postJson('/api/expense-route-templates', [
            'scope' => 'company', 'name' => '本社⇔A社', 'origin' => '本社', 'destination' => 'A社',
            'transport_type' => 'train', 'amount' => 400, 'category_id' => $category->id,
        ])->assertForbidden();

        $created = $this->actingAs($accountingStaff)->postJson('/api/expense-route-templates', [
            'scope' => 'company', 'name' => '本社⇔A社', 'origin' => '本社', 'destination' => 'A社',
            'transport_type' => 'train', 'amount' => 400, 'category_id' => $category->id,
        ]);
        $created->assertCreated();

        // 全社共有テンプレートは全社員の候補一覧に表示される。
        $index = $this->actingAs($employee)->getJson('/api/expense-route-templates');
        $index->assertOk()->assertJsonCount(1);
    }

    public function test_route_templates_index_merges_own_personal_and_all_company_templates(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $accountingStaff = User::factory()->create();
        $accountingStaff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);

        ExpenseRouteTemplate::query()->create([
            'scope' => 'personal', 'employee_id' => $employee->id, 'name' => '自分の経路',
            'origin' => 'A', 'destination' => 'B', 'transport_type' => 'train', 'amount' => 100,
            'category_id' => $category->id, 'created_by' => $employee->id,
        ]);
        ExpenseRouteTemplate::query()->create([
            'scope' => 'personal', 'employee_id' => $otherEmployee->id, 'name' => '他人の経路',
            'origin' => 'C', 'destination' => 'D', 'transport_type' => 'train', 'amount' => 200,
            'category_id' => $category->id, 'created_by' => $otherEmployee->id,
        ]);
        ExpenseRouteTemplate::query()->create([
            'scope' => 'company', 'name' => '全社共有経路',
            'origin' => 'E', 'destination' => 'F', 'transport_type' => 'train', 'amount' => 300,
            'category_id' => $category->id, 'created_by' => $accountingStaff->id,
        ]);

        $response = $this->actingAs($employee)->getJson('/api/expense-route-templates');
        $response->assertOk();
        $names = collect($response->json())->pluck('name');
        $this->assertContains('自分の経路', $names);
        $this->assertContains('全社共有経路', $names);
        $this->assertNotContains('他人の経路', $names);
    }
}
