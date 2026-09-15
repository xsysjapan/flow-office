<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Models\PaidLeaveGrantExpiryPolicy;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveProportionalGrantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 法定Policyマスタ(通常付与表・比例付与表・時効)のversion管理・日数引き当てロジックの
 * 単体テスト(spec.md 論点4・実装対象Phase B)。
 */
class PaidLeaveGrantPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_grant_policy_resolves_exact_month_match(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 18, 'grant_days' => 11, 'is_active' => true]);

        $this->assertSame(10.0, PaidLeaveGrantPolicy::grantDaysFor(6, 'v1'));
        $this->assertSame(11.0, PaidLeaveGrantPolicy::grantDaysFor(18, 'v1'));
    }

    public function test_normal_grant_policy_resolves_highest_matching_step_below_input(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 18, 'grant_days' => 11, 'is_active' => true]);

        // 6か月と1年6か月の間(例: 12か月)は6か月時点の日数がそのまま適用される。
        $this->assertSame(10.0, PaidLeaveGrantPolicy::grantDaysFor(12, 'v1'));
        // 78か月(6年6か月)以降は最終ステップが適用され続ける。
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 78, 'grant_days' => 20, 'is_active' => true]);
        $this->assertSame(20.0, PaidLeaveGrantPolicy::grantDaysFor(200, 'v1'));
    }

    public function test_normal_grant_policy_returns_null_below_first_step(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => true]);

        $this->assertNull(PaidLeaveGrantPolicy::grantDaysFor(3, 'v1'));
    }

    public function test_normal_grant_policy_ignores_inactive_rows(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => false]);

        $this->assertNull(PaidLeaveGrantPolicy::grantDaysFor(6, 'v1'));
    }

    public function test_normal_grant_policy_is_versioned_independently(): void
    {
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v2', 'continuous_service_months' => 6, 'grant_days' => 12, 'is_active' => true]);

        $this->assertSame(10.0, PaidLeaveGrantPolicy::grantDaysFor(6, 'v1'));
        $this->assertSame(12.0, PaidLeaveGrantPolicy::grantDaysFor(6, 'v2'));
    }

    public function test_proportional_grant_policy_resolves_by_category_and_months(): void
    {
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '4', 'continuous_service_months' => 6, 'grant_days' => 7, 'is_active' => true,
        ]);
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '3', 'continuous_service_months' => 6, 'grant_days' => 5, 'is_active' => true,
        ]);

        $this->assertSame(7.0, PaidLeaveProportionalGrantPolicy::grantDaysFor('4', 6, 'v1'));
        $this->assertSame(5.0, PaidLeaveProportionalGrantPolicy::grantDaysFor('3', 6, 'v1'));
    }

    public function test_proportional_grant_policy_resolves_highest_matching_step(): void
    {
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '2', 'continuous_service_months' => 6, 'grant_days' => 3, 'is_active' => true,
        ]);
        PaidLeaveProportionalGrantPolicy::query()->create([
            'version' => 'v1', 'weekly_scheduled_days_category' => '2', 'continuous_service_months' => 18, 'grant_days' => 4, 'is_active' => true,
        ]);

        $this->assertSame(3.0, PaidLeaveProportionalGrantPolicy::grantDaysFor('2', 12, 'v1'));
        $this->assertSame(4.0, PaidLeaveProportionalGrantPolicy::grantDaysFor('2', 18, 'v1'));
    }

    public function test_expiry_policy_returns_configured_years(): void
    {
        PaidLeaveGrantExpiryPolicy::query()->create(['version' => 'v1', 'expiry_years' => 2, 'is_active' => true]);

        $this->assertSame(2, PaidLeaveGrantExpiryPolicy::expiryYearsFor('v1'));
    }

    public function test_expiry_policy_falls_back_to_statutory_default_when_missing(): void
    {
        $this->assertSame(2, PaidLeaveGrantExpiryPolicy::expiryYearsFor('missing-version'));
    }
}
