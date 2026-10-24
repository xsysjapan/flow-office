<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
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

    public function test_updating_rule_without_grant_cycle_type_preserves_the_setting(): void
    {
        // docs/changesets/20260914-port-to-pr112/spec.md レビュー指摘: grant_cycle_typeを送信しない
        // 編集リクエストで、付与方式を既定値で上書きしてしまう回帰を防ぐ(未送信時は既存値を保持する)。
        // 付与方式の初期値が年度はじめになったので、入社日基準のルールで確かめる。
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社共通', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        // grant_cycle_typeを含まないリクエスト。
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
        $this->assertSame(PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY, $rule->grant_cycle_type);
    }

    public function test_updating_rule_can_still_switch_grant_cycle_type_when_sent_explicitly(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社共通', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
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

    private function seedProportionalPolicy(): void
    {
        PaidLeaveProportionalGrantPolicy::query()->create(['version' => 'v1', 'weekly_scheduled_days_category' => '3', 'continuous_service_months' => 6, 'grant_days' => 5, 'is_active' => true]);
        PaidLeaveProportionalGrantPolicy::query()->create(['version' => 'v1', 'weekly_scheduled_days_category' => '2', 'continuous_service_months' => 6, 'grant_days' => 3, 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '全社員',
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
        ], $overrides);
    }

    public function test_store_defaults_grant_cycle_type_to_mass_grant_month_and_does_not_expose_mass_grant_month(): void
    {
        $this->seedNormalPolicy();

        $response = $this->actingAs($this->admin())->postJson('/api/paid-leave/grant-rules', $this->rulePayload([
            'steps' => [['continuous_service_months' => 6, 'grant_days' => 10]],
        ]));

        $response->assertCreated();
        $response->assertJsonPath('grant_cycle_type', PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH);
        $this->assertArrayNotHasKey('mass_grant_month', $response->json());
        $this->assertSame(
            PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
            PaidLeaveGrantRule::query()->findOrFail($response->json('id'))->grant_cycle_type,
        );
    }

    public function test_store_accepts_steps_by_grant_category_and_defaults_category_to_normal(): void
    {
        $this->seedNormalPolicy();
        $this->seedProportionalPolicy();

        $response = $this->actingAs($this->admin())->postJson('/api/paid-leave/grant-rules', $this->rulePayload([
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
                ['grant_category' => 'normal', 'continuous_service_months' => 18, 'grant_days' => 11],
                ['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 5],
            ],
        ]));

        $response->assertCreated();
        $response->assertJsonPath('grant_cycle_type', PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY);
        $this->assertEqualsCanonicalizing(
            ['normal:6:10', 'normal:18:11', 'proportional_3:6:5'],
            collect($response->json('steps'))
                ->map(fn (array $step) => "{$step['grant_category']}:{$step['continuous_service_months']}:{$step['grant_days']}")
                ->all(),
        );
    }

    public function test_store_checks_statutory_minimum_per_grant_category(): void
    {
        $this->seedNormalPolicy();
        $this->seedProportionalPolicy();
        $admin = $this->admin();

        // 通常の6か月は10日以上が必要だが、週3日の表は5日以上でよい。5日は通常では下回り、週3日では下回らない。
        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', $this->rulePayload([
            'steps' => [
                ['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 5],
                ['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 5],
                ['grant_category' => 'proportional_2', 'continuous_service_months' => 6, 'grant_days' => 2],
            ],
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['steps.0.grant_days', 'steps.2.grant_days']);
        $this->assertArrayNotHasKey('steps.1.grant_days', $response->json('errors'));
        $this->assertSame(0, PaidLeaveGrantRule::query()->where('name', '全社員')->count());
    }

    public function test_store_rejects_unknown_grant_category_and_duplicate_months_within_a_category(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', $this->rulePayload([
            'steps' => [['grant_category' => 'proportional_5', 'continuous_service_months' => 6, 'grant_days' => 10]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.grant_category']);

        $this->actingAs($admin)->postJson('/api/paid-leave/grant-rules', $this->rulePayload([
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
                ['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 11],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.1.continuous_service_months']);
    }

    public function test_update_checks_statutory_minimum_per_grant_category_and_replaces_steps(): void
    {
        $this->seedNormalPolicy();
        $this->seedProportionalPolicy();
        $admin = $this->admin();
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", $this->rulePayload([
            'steps' => [['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 4]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.grant_days']);

        $this->actingAs($admin)->putJson("/api/paid-leave/grant-rules/{$rule->id}", $this->rulePayload([
            'steps' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
                ['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 5],
            ],
        ]))->assertOk();

        $this->assertSame(2, $rule->steps()->count());
        $this->assertSame(5.0, (float) $rule->steps()->where('grant_category', 'proportional_3')->value('grant_days'));
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
