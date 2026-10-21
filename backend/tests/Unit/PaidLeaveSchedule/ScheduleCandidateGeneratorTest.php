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
use Illuminate\Support\Facades\DB;
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
        return new ScheduleCandidateGenerator(new GrantCategoryClassifier);
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

    public function test_front_loaded_and_subsequent_grants_resolve_determinate_days_matching_nominal_tier(): void
    {
        // レビュー指摘の回帰テスト: continuousServiceMonthsを実際の暦月数
        // (前倒しの場合6ヶ月未満になる)ではなく、名目上の付与回数ベースの月数
        // (初回=first_grant_after_months、以降+12ずつ)で解決しないと、
        // 前倒し付与エントリのstepsが一致せず常にNeedsReview(isDeterminate=false)に
        // なってしまう不具合を検証する。
        $workStyle = $this->createWorkStyle();
        $rule = $this->createMassGrantRule($workStyle);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['continuous_service_months' => 18, 'grant_days' => 11]);

        // 12月入社: 前倒しで2026-04-01が初回付与(実経過月数は4ヶ月)。
        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2028-12-31'));

        $this->assertSame('2026-04-01', $candidates[0]['scheduledOn']);
        $this->assertTrue($candidates[0]['isDeterminate'], '前倒しされた初回付与も候補日数を確定できること');
        $this->assertSame(10.0, $candidates[0]['candidateGrantDays']);

        $this->assertSame('2027-04-01', $candidates[1]['scheduledOn']);
        $this->assertTrue($candidates[1]['isDeterminate']);
        $this->assertSame(11.0, $candidates[1]['candidateGrantDays']);
    }

    public function test_six_month_branch_also_resolves_determinate_days(): void
    {
        $workStyle = $this->createWorkStyle();
        $rule = $this->createMassGrantRule($workStyle);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);

        // 4月入社: 一斉付与月(4月)より6ヶ月後(10月)の方が早いため6ヶ月後付与。
        $user = User::factory()->create(['hire_date' => '2025-04-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2026-12-31'));

        $this->assertSame('2025-10-01', $candidates[0]['scheduledOn']);
        $this->assertTrue($candidates[0]['isDeterminate']);
        $this->assertSame(10.0, $candidates[0]['candidateGrantDays']);
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

    // ---- userIdsCurrentlyOn(勤務形態を更新したときの再計算対象) ----

    private function createOtherWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'OTHER',
            'name' => '別勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 1440,
            'weekly_scheduled_days' => 3,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    public function test_user_ids_currently_on_includes_user_whose_latest_assignment_up_to_this_month_is_the_work_style(): void
    {
        $workStyle = $this->createWorkStyle();
        $other = $this->createOtherWorkStyle();
        $user = User::factory()->create();
        // 過去月の割当が別の勤務形態でも、当月以前で最新(当月)の割当が対象の勤務形態なら含む。
        $this->assignWorkStyle($user, $other, '2026-01');
        $this->assignWorkStyle($user, $workStyle, '2026-10');

        $this->assertSame([$user->id], $this->generator()->userIdsCurrentlyOn($workStyle->id, Carbon::parse('2026-10-21')));
    }

    public function test_user_ids_currently_on_excludes_user_moved_to_another_work_style_in_a_later_month(): void
    {
        $workStyle = $this->createWorkStyle();
        $other = $this->createOtherWorkStyle();
        $user = User::factory()->create();
        $this->assignWorkStyle($user, $workStyle, '2026-01');
        $this->assignWorkStyle($user, $other, '2026-09');

        $this->assertSame([], $this->generator()->userIdsCurrentlyOn($workStyle->id, Carbon::parse('2026-10-21')));
        $this->assertSame([$user->id], $this->generator()->userIdsCurrentlyOn($other->id, Carbon::parse('2026-10-21')));
    }

    public function test_user_ids_currently_on_includes_user_assigned_only_in_a_past_month(): void
    {
        $workStyle = $this->createWorkStyle();
        $user = User::factory()->create();
        $this->assignWorkStyle($user, $workStyle, '2026-01');

        $this->assertSame([$user->id], $this->generator()->userIdsCurrentlyOn($workStyle->id, Carbon::parse('2026-10-21')));
    }

    public function test_user_ids_currently_on_excludes_user_assigned_only_after_this_month(): void
    {
        $workStyle = $this->createWorkStyle();
        $user = User::factory()->create();
        $this->assignWorkStyle($user, $workStyle, '2026-11');

        $this->assertSame([], $this->generator()->userIdsCurrentlyOn($workStyle->id, Carbon::parse('2026-10-21')));
    }

    /**
     * created_atはEloquentの自動設定を避けるため、作成後にDBで直接設定する(並び順の検証用)。
     */
    private function createGlobalRule(string $name, string $createdAt): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => $name,
            'work_style_id' => null,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);
        DB::table('paid_leave_grant_rules')->where('id', $rule->id)->update(['created_at' => $createdAt]);

        return $rule;
    }

    /**
     * マイグレーションの全社共通ルールを無効化する(このテストの対象の件数を明確にするため)。
     */
    private function deactivateSeededGlobalRules(): void
    {
        PaidLeaveGrantRule::query()->whereNull('work_style_id')->update(['is_active' => false]);
    }

    public function test_matching_rule_picks_the_oldest_global_rule_when_there_are_several(): void
    {
        $this->deactivateSeededGlobalRules();

        // 新しい方を先に作っても、created_atが古い方が選ばれる。
        $newer = $this->createGlobalRule('新しい全社ルール', '2026-02-01 00:00:00');
        $older = $this->createGlobalRule('古い全社ルール', '2026-01-01 00:00:00');

        $this->assertSame($older->id, $this->generator()->matchingRuleFor(null)?->id);
        $this->assertNotSame($newer->id, $this->generator()->matchingRuleFor(null)?->id);
    }

    public function test_matching_rule_prefers_work_style_rule_over_global_rule(): void
    {
        $this->deactivateSeededGlobalRules();
        $workStyle = $this->createWorkStyle();
        $global = $this->createGlobalRule('全社ルール', '2026-01-01 00:00:00');
        $specific = PaidLeaveGrantRule::query()->create([
            'name' => '勤務形態ルール',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);
        // 勤務形態ルールは全社ルールより新しい作成日時でも優先されることを確かめる。
        DB::table('paid_leave_grant_rules')->where('id', $specific->id)->update(['created_at' => '2026-03-01 00:00:00']);

        $this->assertSame($specific->id, $this->generator()->matchingRuleFor($workStyle)?->id);
        $this->assertSame($global->id, $this->generator()->matchingRuleFor(null)?->id);
    }
}
