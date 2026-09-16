<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_updating_rule_without_grant_cycle_type_preserves_mass_grant_month_setting(): void
    {
        // docs/changesets/20260914-port-to-pr112/spec.md レビュー指摘: 既存の編集フォームは
        // grant_cycle_type/mass_grant_monthを送信しないため、これらのフィールドを
        // 常に既定値(anniversary/null)で上書きすると、一斉付与ルールの設定が
        // 編集のたびに意図せずリセットされてしまう回帰を防ぐ。
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社共通', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, 'mass_grant_month' => 4,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        // grant_cycle_type/mass_grant_monthを含まないリクエスト(既存の編集フォーム相当)。
        $response = $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", [
            'name' => '全社共通',
            'min_attendance_rate' => 90,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
            ],
        ]);

        $response->assertOk();
        $rule->refresh();
        $this->assertSame(90, $rule->min_attendance_rate);
        $this->assertSame(PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, $rule->grant_cycle_type);
        $this->assertSame(4, $rule->mass_grant_month);
    }

    public function test_updating_rule_can_still_switch_grant_cycle_type_when_sent_explicitly(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社共通', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, 'mass_grant_month' => 4,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        $response = $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", [
            'name' => '全社共通',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
            ],
        ]);

        $response->assertOk();
        $rule->refresh();
        $this->assertSame(PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY, $rule->grant_cycle_type);
        $this->assertNull($rule->mass_grant_month);
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

    public function test_store_and_update_dispatch_the_schedule_reapply_job(): void
    {
        // docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md:
        // 付与ルールの作成・編集は、未確定Scheduleをポリシー変更に追従させる
        // ReapplyPaidLeaveSchedulePolicyJobを発行する。
        Queue::fake();
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', [
            'name' => '全社員',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
            ],
        ]);
        $response->assertCreated();
        $ruleId = $response->json('id');

        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);

        $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$ruleId}", [
            'name' => '全社員',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 11],
            ],
        ])->assertOk();

        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 2);
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
