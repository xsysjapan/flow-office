<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * docs/changesets/20260913-paid-leave-fiscal-grant-and-nav/spec.md の
 * mass_grant_month前倒しアルゴリズムを、PR#112のScheduleCandidateGenerator構造に
 * 移植した実装(20260914-port-to-pr112)を検証する。
 */
class ScheduleCandidateGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): ScheduleCandidateGenerator
    {
        return new ScheduleCandidateGenerator(new GrantCategoryClassifier());
    }

    private function createWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'NORMAL',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    private function assignWorkStyle(User $user, WorkStyle $workStyle, string $yearMonth = '2020-01'): void
    {
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => $yearMonth,
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);
    }

    private function seedNormalPolicyUpTo(int $days = 20): void
    {
        foreach (range(0, 66, 6) as $months) {
            PaidLeaveGrantPolicy::query()->create([
                'version' => 'v1', 'continuous_service_months' => $months, 'grant_days' => $days,
                'effective_from' => '2000-01-01', 'is_active' => true,
            ]);
        }
    }

    private function createMassGrantRule(WorkStyle $workStyle, int $massGrantMonth = 4): PaidLeaveGrantRule
    {
        return PaidLeaveGrantRule::query()->create([
            'name' => '一斉付与(work_style固有)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
            'mass_grant_month' => $massGrantMonth,
            'is_active' => true,
        ]);
    }

    public function test_front_loaded_branch_for_hire_dates_shortly_before_mass_grant_month(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();
        $this->createMassGrantRule($workStyle);

        // 12月入社: 6ヶ月後(6/1)より、直後の4月(4/1)の方が早いため前倒し。
        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame('2026-04-01', $candidates[0]['scheduledOn']);
    }

    public function test_six_month_branch_for_hire_dates_far_from_mass_grant_month(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();
        $this->createMassGrantRule($workStyle);

        // 5月入社: 6ヶ月後(11/1)の方が、直後の4月(翌年4/1)より早いため6ヶ月経過時点。
        $user = User::factory()->create(['hire_date' => '2025-05-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame('2025-11-01', $candidates[0]['scheduledOn']);
    }

    public function test_boundary_hire_date_exactly_six_months_before_mass_grant_month(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();
        $this->createMassGrantRule($workStyle);

        // 10月1日入社: 6ヶ月後がちょうど4月1日。次の一斉付与日(4/1)とsixMonthMark(4/1)が
        // 同日の場合、nextMassGrantDate.lt(sixMonthMark)はfalseなのでsixMonthMarkが採用される
        // (結果として同じ4/1)。
        $user = User::factory()->create(['hire_date' => '2025-10-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame('2026-04-01', $candidates[0]['scheduledOn']);
    }

    public function test_subsequent_grants_roll_over_annually(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();
        $this->createMassGrantRule($workStyle);

        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2029-12-31'));

        $dates = array_column($candidates, 'scheduledOn');
        $this->assertSame(['2026-04-01', '2027-04-01', '2028-04-01', '2029-04-01'], $dates);
    }

    public function test_anniversary_type_rules_are_unaffected(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();

        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '周年ルール',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['continuous_service_months' => 18, 'grant_days' => 11]);

        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $dates = array_column($candidates, 'scheduledOn');
        $this->assertSame(['2026-06-01', '2027-06-01'], $dates);
        $this->assertEquals(10.0, $candidates[0]['candidateGrantDays']);
        $this->assertTrue($candidates[0]['isDeterminate']);
    }

    public function test_no_matching_rule_yields_no_candidates(): void
    {
        $workStyle = $this->createWorkStyle();
        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        // work_style固有ルールを作らず、マイグレーションでシードされた全社共通ルールも
        // 無効化する(=work_style固有・全社共通のいずれも存在しないケース)。
        PaidLeaveGrantRule::query()->whereNull('work_style_id')->update(['is_active' => false]);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame([], $candidates);
    }

    public function test_rule_without_covering_step_is_flagged_indeterminate(): void
    {
        $workStyle = $this->createWorkStyle();
        PaidLeaveGrantRule::query()->create([
            'name' => 'ステップ無し',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertNotEmpty($candidates);
        foreach ($candidates as $candidate) {
            $this->assertFalse($candidate['isDeterminate']);
            $this->assertSame(0.0, $candidate['candidateGrantDays']);
        }
    }

    public function test_usage_start_date_guard_skips_earlier_candidates(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();

        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '周年ルール',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['continuous_service_months' => 18, 'grant_days' => 11]);

        $user = User::factory()->create([
            'hire_date' => '2025-12-01',
            'employment_status' => 'active',
            'usage_start_date' => '2026-07-01',
        ]);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $dates = array_column($candidates, 'scheduledOn');
        $this->assertSame(['2027-06-01'], $dates);
    }
}
