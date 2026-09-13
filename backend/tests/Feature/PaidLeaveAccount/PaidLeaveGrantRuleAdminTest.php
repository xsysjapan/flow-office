<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 有給付与ルールの編集・削除 (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md
 * 論点15-1・論点16)。作成のみだった既存`PaidLeaveGrantRule`管理APIへの追加分。
 */
class PaidLeaveGrantRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $admin;
    }

    private function seedNormalPolicy(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 18, 'grant_days' => 11, 'is_active' => true]);
    }

    public function test_employee_cannot_update_rule(): void
    {
        $employee = User::factory()->create();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);

        $this->actingAs($employee)->putJson("/api/paid-leave/grant-rules/{$rule->id}", [
            'name' => '変更後',
        ])->assertForbidden();
    }

    public function test_admin_can_edit_rule_content(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        $response = $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", [
            'name' => '全社員(改)',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 12],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('全社員(改)', $response->json('name'));
        $this->assertCount(1, $response->json('steps'));
        $this->assertSame(12, $response->json('steps.0.grant_days'));

        $rule->refresh();
        $this->assertSame('全社員(改)', $rule->name);
        $this->assertCount(1, $rule->steps()->get());
    }

    public function test_update_rejects_step_below_statutory_minimum(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", [
            'name' => '全社員',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                // 6か月時点の法定最低日数(10日)を下回る
                ['continuous_service_months' => 6, 'grant_days' => 5],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['steps.0.grant_days']);
    }

    public function test_store_rejects_step_below_statutory_minimum(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', [
            'name' => '全社員',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 5],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['steps.0.grant_days']);
    }

    public function test_store_allows_step_above_statutory_minimum(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', [
            'name' => '全社員(手厚め)',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 15],
            ],
        ]);

        $response->assertCreated();
    }

    public function test_active_rule_cannot_be_physically_deleted(): void
    {
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/paid-leave/grant-rules/{$rule->id}");

        $response->assertUnprocessable();
        $this->assertNotNull(PaidLeaveGrantRule::query()->find($rule->id));
    }

    public function test_deactivated_rule_can_be_deleted(): void
    {
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/paid-leave/grant-rules/{$rule->id}");

        $response->assertNoContent();
        $this->assertNull(PaidLeaveGrantRule::query()->find($rule->id));
    }
}
