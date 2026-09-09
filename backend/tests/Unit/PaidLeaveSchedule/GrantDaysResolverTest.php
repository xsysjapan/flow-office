<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\PaidLeaveSchedule\Support\GrantDaysResolver;
use App\Models\CompanyCalendar;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveGrantRuleStep;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GrantDaysResolver`: 「独自ルール優先、無ければ法定Policy(区分に応じて通常/比例)」の
 * 優先順位(spec.md論点5)。
 */
class GrantDaysResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // seed()はDatabaseSeeder全体を走らせず、法定Policyのみ投入する。
        $this->seed(\Database\Seeders\PaidLeaveGrantPolicySeeder::class);
    }

    private function createWorkStyle(array $overrides = []): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create(array_merge([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ], $overrides));
    }

    public function test_custom_rule_takes_priority_over_legal_policy(): void
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '独自ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        PaidLeaveGrantRuleStep::query()->create(['rule_id' => $rule->id, 'continuous_service_months' => 18, 'grant_days' => 99.0]);
        $rule->load('steps');

        $resolver = new GrantDaysResolver;
        $days = $resolver->resolve($rule, 18, GrantCategory::REGULAR, null);

        $this->assertSame(99.0, $days);
    }

    public function test_regular_category_without_custom_rule_uses_legal_table_at_18_months(): void
    {
        $resolver = new GrantDaysResolver;
        $days = $resolver->resolve(null, 18, GrantCategory::REGULAR, null);

        $this->assertSame(11.0, $days);
    }

    public function test_regular_category_uses_the_highest_milestone_not_exceeding_months(): void
    {
        $resolver = new GrantDaysResolver;

        $this->assertSame(10.0, $resolver->resolve(null, 6, GrantCategory::REGULAR, null));
        $this->assertSame(10.0, $resolver->resolve(null, 17, GrantCategory::REGULAR, null));
        $this->assertSame(20.0, $resolver->resolve(null, 100, GrantCategory::REGULAR, null));
    }

    public function test_proportional_category_uses_weekly_scheduled_days_bucket(): void
    {
        $resolver = new GrantDaysResolver;
        $workStyle = $this->createWorkStyle(['weekly_scheduled_days' => 3]);

        $days = $resolver->resolve(null, 18, GrantCategory::PROPORTIONAL, $workStyle);

        $this->assertSame(6.0, $days);
    }

    public function test_proportional_category_without_weekly_scheduled_days_returns_zero(): void
    {
        $resolver = new GrantDaysResolver;
        $workStyle = $this->createWorkStyle(['weekly_scheduled_days' => null]);

        $days = $resolver->resolve(null, 18, GrantCategory::PROPORTIONAL, $workStyle);

        $this->assertSame(0.0, $days);
    }
}
