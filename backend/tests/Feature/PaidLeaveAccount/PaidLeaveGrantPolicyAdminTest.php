<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 法定通常付与表・比例付与表の新バージョン作成
 * (docs/changesets/20260914-port-to-pr112/spec.md Feature 5)。既存versionは一切
 * 変更せず、新versionとして追記する(CLAUDE.md原則8)。
 */
class PaidLeaveGrantPolicyAdminTest extends TestCase
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

    private function seedProportionalPolicy(): void
    {
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 7, 'is_active' => true,
        ]);
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '3', 'continuous_service_months' => 6, 'grant_days' => 5, 'is_active' => true,
        ]);
    }

    public function test_get_returns_only_current_version_rows(): void
    {
        $this->seedNormalPolicy();
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson('/api/paid-leave/grant-policies');

        $response->assertOk();
        $this->assertSame('v1', $response->json('data.version'));
        $this->assertCount(2, $response->json('data.normal'));
    }

    public function test_employee_cannot_create_new_normal_policy_version(): void
    {
        $this->seedNormalPolicy();
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/paid-leave/grant-policies', [
            'rows' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_create_new_normal_policy_version_without_touching_old_rows(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-policies', [
            'rows' => [
                ['continuous_service_months' => 6, 'grant_days' => 11],
                ['continuous_service_months' => 18, 'grant_days' => 12],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('v2', $response->json('data.version'));

        // 旧versionの行は変更されていない
        $this->assertSame(10.0, (float) PaidLeaveGrantPolicy::query()->where('version', 'v1')->where('continuous_service_months', 6)->value('grant_days'));
        $this->assertCount(2, PaidLeaveGrantPolicy::query()->where('version', 'v1')->get());
        $this->assertCount(2, PaidLeaveGrantPolicy::query()->where('version', 'v2')->get());

        // 以降のcurrentVersion()/日数解決は新versionを参照する
        $this->assertSame('v2', PaidLeaveGrantPolicy::latestVersion());
        $this->assertSame(11.0, PaidLeaveGrantPolicy::grantDaysFor(6, 'v2'));
    }

    public function test_store_normal_and_proportional_policy_dispatch_the_schedule_reapply_job(): void
    {
        // docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md:
        // 法定付与ポリシーの新バージョン作成は、未確定Scheduleをポリシー変更に追従させる
        // ReapplyPaidLeaveSchedulePolicyJobを発行する。
        Queue::fake();
        $this->seedNormalPolicy();
        $this->seedProportionalPolicy();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/paid-leave/grant-policies', [
            'rows' => [
                ['continuous_service_months' => 6, 'grant_days' => 11],
            ],
        ])->assertCreated();

        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 1);

        $this->actingAs($admin)->postJson('/api/paid-leave/proportional-grant-policies', [
            'rows' => [
                ['weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 8],
            ],
        ])->assertCreated();

        Queue::assertPushed(ReapplyPaidLeaveSchedulePolicyJob::class, 2);
    }

    public function test_store_rejects_duplicate_continuous_service_months(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-policies', [
            'rows' => [
                ['continuous_service_months' => 6, 'grant_days' => 10],
                ['continuous_service_months' => 6, 'grant_days' => 11],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['rows']);
    }

    public function test_store_rejects_negative_or_missing_values(): void
    {
        $this->seedNormalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/grant-policies', [
            'rows' => [
                ['continuous_service_months' => 6, 'grant_days' => -1],
                ['grant_days' => 10],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['rows.0.grant_days', 'rows.1.continuous_service_months']);
    }

    public function test_employee_cannot_create_new_proportional_policy_version(): void
    {
        $this->seedProportionalPolicy();
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/paid-leave/proportional-grant-policies', [
            'rows' => [
                ['weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 7],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_create_new_proportional_policy_version_without_touching_old_rows(): void
    {
        $this->seedProportionalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/proportional-grant-policies', [
            'rows' => [
                ['weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 8],
                ['weekly_scheduled_days_category' => '3', 'continuous_service_months' => 6, 'grant_days' => 6],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('v2', $response->json('data.version'));

        $this->assertSame(7.0, (float) PaidLeaveProportionalGrantPolicy::query()->where('version', 'v1')->where('weekly_scheduled_days_category', '4')->value('grant_days'));
        $this->assertCount(2, PaidLeaveProportionalGrantPolicy::query()->where('version', 'v2')->get());

        $this->assertSame('v2', PaidLeaveProportionalGrantPolicy::latestVersion());
        $this->assertSame(8.0, PaidLeaveProportionalGrantPolicy::grantDaysFor('4', 6, 'v2'));
    }

    public function test_proportional_store_rejects_duplicate_pairs(): void
    {
        $this->seedProportionalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/proportional-grant-policies', [
            'rows' => [
                ['weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 8],
                ['weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 9],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['rows']);
    }

    public function test_proportional_store_rejects_out_of_range_category(): void
    {
        $this->seedProportionalPolicy();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/proportional-grant-policies', [
            'rows' => [
                ['weekly_scheduled_days_category' => '5', 'continuous_service_months' => 6, 'grant_days' => 8],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['rows.0.weekly_scheduled_days_category']);
    }
}
