<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\PaidLeaveFiscalYearSetting;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function createMassGrantRule(WorkStyle $workStyle): PaidLeaveGrantRule
    {
        return PaidLeaveGrantRule::query()->create([
            'name' => '一斉付与(work_style固有)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
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

        // 10月1日入社: 6ヶ月後がちょうど4月1日。年度開始日(4/1)と半年の日が同日の場合は前倒しではないので、
        // 4/1の1件(名目6か月)だけ。次は翌年の4/1(名目18か月)。
        $user = User::factory()->create(['hire_date' => '2025-10-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(['2026-04-01', '2027-04-01'], array_slice(array_column($candidates, 'scheduledOn'), 0, 2));
        $this->assertSame([6, 18], array_slice(array_column($candidates, 'nominalServiceMonths'), 0, 2));
    }

    public function test_subsequent_grants_roll_over_annually(): void
    {
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyle();
        $this->createMassGrantRule($workStyle);

        $user = User::factory()->create(['hire_date' => '2025-12-01', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2025-01-01'), Carbon::parse('2029-12-31'));

        // 前倒しの初回は、同じ年度開始日に名目6か月と18か月の2件をまとめて付与する。以後は毎年の年度開始日。
        $dates = array_column($candidates, 'scheduledOn');
        $this->assertSame(['2026-04-01', '2026-04-01', '2027-04-01', '2028-04-01', '2029-04-01'], $dates);
        $this->assertSame([6, 18, 30, 42, 54], array_column($candidates, 'nominalServiceMonths'));
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

        // 同じ日にまとめた2件目(名目18か月)。
        $this->assertSame('2026-04-01', $candidates[1]['scheduledOn']);
        $this->assertSame(18, $candidates[1]['nominalServiceMonths']);
        $this->assertTrue($candidates[1]['isDeterminate']);
        $this->assertSame(11.0, $candidates[1]['candidateGrantDays']);

        // 3件目(名目30か月)は翌年の年度開始日。stepsは18か月までなので18か月の段(11日)を使う。
        $this->assertSame('2027-04-01', $candidates[2]['scheduledOn']);
        $this->assertSame(30, $candidates[2]['nominalServiceMonths']);
        $this->assertTrue($candidates[2]['isDeterminate']);
        $this->assertSame(11.0, $candidates[2]['candidateGrantDays']);
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

    // ---- 年度はじめ一斉付与(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3・10) ----

    private function setFiscalYearStart(int $month, int $day): void
    {
        PaidLeaveFiscalYearSetting::query()->updateOrCreate(
            ['id' => PaidLeaveFiscalYearSetting::SINGLETON_ID],
            ['company_calendar_id' => null, 'fiscal_year_start_month' => $month, 'fiscal_year_start_day' => $day],
        );
    }

    /**
     * 通常の社員と、一斉付与のルール(区分ごとの表)を作る。
     *
     * @param  array<string, array<int, int>>  $tables  区分 => (継続勤務月数 => 付与日数)
     */
    private function createRuleWithTables(WorkStyle $workStyle, array $tables, string $cycleType = PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '一斉付与(区分ごとの表)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => $cycleType,
            'is_active' => true,
        ]);

        foreach ($tables as $category => $rows) {
            foreach ($rows as $months => $days) {
                $rule->steps()->create(['grant_category' => $category, 'continuous_service_months' => $months, 'grant_days' => $days]);
            }
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWorkStyleWith(array $attributes): WorkStyle
    {
        return WorkStyle::query()->create(array_merge([
            'code' => 'WS'.fake()->unique()->numberBetween(1000, 9999),
            'name' => '勤務形態',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 1440,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ], $attributes));
    }

    /**
     * @return array<int, array{string, int, float, bool}>
     */
    private function summarize(array $candidates): array
    {
        return array_map(fn (array $c) => [$c['scheduledOn'], $c['nominalServiceMonths'], $c['candidateGrantDays'], $c['isDeterminate']], $candidates);
    }

    private function userHiredOn(string $hireDate, WorkStyle $workStyle, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['hire_date' => $hireDate, 'employment_status' => 'active'], $attributes));
        $this->assignWorkStyle($user, $workStyle, '2020-01');

        return $user;
    }

    private function normalTable(): array
    {
        return ['normal' => [6 => 10, 18 => 11, 30 => 12, 42 => 14]];
    }

    /**
     * @return array<string, array{string, string, array<int, array{string, int, float}>}>
     */
    public static function fiscalStartCases(): array
    {
        return [
            // 年度開始4/1
            '4/1: 2026-01-01入社は前倒しで4/1に2件、翌年に名目30' => ['2026-04-01', '4/1', '2026-01-01', [
                ['2026-04-01', 6, 10.0], ['2026-04-01', 18, 11.0], ['2027-04-01', 30, 12.0],
            ]],
            '4/1: 2025-10-01入社は半年の日と同じ日なので1件、翌年に名目18' => ['2026-04-01', '4/1', '2025-10-01', [
                ['2026-04-01', 6, 10.0], ['2027-04-01', 18, 11.0],
            ]],
            '4/1: 2026-04-01入社は半年後の10/1に名目6、翌年4/1に名目18' => ['2026-04-01', '4/1', '2026-04-01', [
                ['2026-10-01', 6, 10.0], ['2027-04-01', 18, 11.0],
            ]],
            '4/1: 2026-03-31入社は翌日の4/1に2件' => ['2026-04-01', '4/1', '2026-03-31', [
                ['2026-04-01', 6, 10.0], ['2026-04-01', 18, 11.0], ['2027-04-01', 30, 12.0],
            ]],
            // 年度開始10/1
            '10/1: 2026-01-01入社は半年(7/1)の方が早いので7/1に名目6、10/1に名目18' => ['2026-10-01', '10/1', '2026-01-01', [
                ['2026-07-01', 6, 10.0], ['2026-10-01', 18, 11.0], ['2027-10-01', 30, 12.0],
            ]],
            '10/1: 2026-06-01入社は前倒しで10/1に2件' => ['2026-10-01', '10/1', '2026-06-01', [
                ['2026-10-01', 6, 10.0], ['2026-10-01', 18, 11.0], ['2027-10-01', 30, 12.0],
            ]],
            // 年度開始4/16
            '4/16: 2026-01-01入社は前倒しで4/16に2件' => ['2026-04-16', '4/16', '2026-01-01', [
                ['2026-04-16', 6, 10.0], ['2026-04-16', 18, 11.0], ['2027-04-16', 30, 12.0],
            ]],
            '4/16: 2025-10-16入社は半年の日と同じ日なので1件' => ['2026-04-16', '4/16', '2025-10-16', [
                ['2026-04-16', 6, 10.0], ['2027-04-16', 18, 11.0],
            ]],
        ];
    }

    /**
     * @param  array<int, array{string, int, float}>  $expected  [付与予定日, 名目の月数, 日数]
     */
    #[DataProvider('fiscalStartCases')]
    public function test_mass_grant_dates_follow_the_fiscal_year_start(string $fiscalStart, string $label, string $hireDate, array $expected): void
    {
        [, $month, $day] = array_map('intval', explode('-', $fiscalStart));
        $this->setFiscalYearStart($month, $day);
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn($hireDate, $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse($expected[array_key_last($expected)][0]));

        $this->assertSame(
            array_map(fn (array $row) => [$row[0], $row[1], $row[2], true], $expected),
            $this->summarize($candidates),
            "年度開始{$label}・入社{$hireDate}",
        );
    }

    public function test_without_a_fiscal_year_setting_the_fiscal_year_starts_on_april_first(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(['2026-04-01', '2026-04-01', '2027-04-01'], array_column($candidates, 'scheduledOn'));
    }

    public function test_the_two_front_loaded_entries_have_distinct_entry_ids(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertCount(3, array_unique(array_column($candidates, 'entryId')));
    }

    public function test_candidates_are_limited_to_the_requested_period(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);

        // 2026-04-01の2件は期間(from=2026-04-02)より前、2028-04-01は期間(to=2027-12-31)より後。
        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2026-04-02'), Carbon::parse('2027-12-31'));

        $this->assertSame([['2027-04-01', 30]], array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths']], $candidates));
    }

    // ---- 日付の計算(addMonthsNoOverflow) ----

    public function test_august_31_hire_does_not_overflow_into_march_in_both_cycle_types(): void
    {
        // 8/31入社の半年後: addMonthsだと2/31が3/3にあふれるが、addMonthsNoOverflowは月末の2/28にする。
        // (法定の3/1より遅れない。前倒しは可)。年度開始は半年の日より後の4/1。
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2025-08-31', $workStyle);

        $mass = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));
        $this->assertSame(['2026-02-28', 6], [$mass[0]['scheduledOn'], $mass[0]['nominalServiceMonths']]);
        $this->assertSame(['2026-04-01', 18], [$mass[1]['scheduledOn'], $mass[1]['nominalServiceMonths']]);

        PaidLeaveGrantRule::query()->where('work_style_id', $workStyle->id)->update(['grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY]);
        $anniversary = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));
        $this->assertSame(['2026-02-28', '2027-02-28'], array_column($anniversary, 'scheduledOn'));
    }

    // ---- 付与済みを起点にした次回 ----

    private function createGrantedEntry(User $user, string $scheduledOn, int $nominalMonths, string $status = PaidLeaveScheduleEntry::STATUS_GRANTED): PaidLeaveScheduleEntry
    {
        return PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => $scheduledOn,
            'category' => GrantCategoryClassifier::CATEGORY_NORMAL,
            'candidate_grant_days' => 10,
            'nominal_service_months' => $nominalMonths,
            'status' => $status,
        ]);
    }

    public function test_next_grant_starts_from_the_last_granted_entry_with_the_same_fiscal_start(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);
        $this->createGrantedEntry($user, '2026-04-01', 6);
        $this->createGrantedEntry($user, '2026-04-01', 18);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2028-12-31'));

        // 2件付与済みなら、名目の大きい方(18)+12=30が次(2027-04-01)。
        $this->assertSame([['2027-04-01', 30], ['2028-04-01', 42]], array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths']], $candidates));
        $this->assertSame([12.0, 14.0], array_column($candidates, 'candidateGrantDays'));
    }

    public function test_next_grant_after_fiscal_start_moves_to_october_comes_within_the_same_year(): void
    {
        // 4/1付与済み(名目6)→年度開始を10/1に変更→同じ年の10/1に名目18。1年を超えて遅らせない。
        $this->setFiscalYearStart(10, 1);
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2025-10-01', $workStyle);
        $this->createGrantedEntry($user, '2026-04-01', 6);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2028-12-31'));

        $this->assertSame(
            [['2026-10-01', 18], ['2027-10-01', 30], ['2028-10-01', 42]],
            array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths']], $candidates),
        );
    }

    public function test_pair_granted_then_fiscal_start_changed_continues_from_the_larger_nominal_months(): void
    {
        $this->setFiscalYearStart(10, 1);
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);
        $this->createGrantedEntry($user, '2026-04-01', 6);
        $this->createGrantedEntry($user, '2026-04-01', 18);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(
            [['2026-10-01', 30], ['2027-10-01', 42]],
            array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths']], $candidates),
        );
    }

    public function test_only_granted_entries_are_the_starting_point(): void
    {
        // 付与済みでない行(予定・取消)は起点にしない。入社日から並べる。
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);
        $this->createGrantedEntry($user, '2027-04-01', 30, PaidLeaveScheduleEntry::STATUS_SCHEDULED);
        $this->createGrantedEntry($user, '2027-04-01', 30, PaidLeaveScheduleEntry::STATUS_CANCELLED);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(['2026-04-01', '2026-04-01', '2027-04-01'], array_column($candidates, 'scheduledOn'));
    }

    public function test_granted_entries_of_other_users_are_ignored(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle);
        $other = $this->userHiredOn('2026-01-01', $workStyle);
        $this->createGrantedEntry($other, '2026-04-01', 18);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(['2026-04-01', '2026-04-01', '2027-04-01'], array_column($candidates, 'scheduledOn'));
    }

    // ---- 利用開始日 ----

    public function test_usage_start_date_skips_earlier_entries_and_starts_from_the_next_grant_date(): void
    {
        // 2026-01-01入社・利用開始2026-10-01: 2026-04-01の2件は作らず、2027-04-01の名目30(12日)から。
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle, ['usage_start_date' => '2026-10-01']);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2028-12-31'));

        $this->assertSame(
            [['2027-04-01', 30, 12.0], ['2028-04-01', 42, 14.0]],
            array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths'], $c['candidateGrantDays']], $candidates),
        );
    }

    public function test_entries_on_the_usage_start_date_itself_are_created(): void
    {
        $workStyle = $this->createWorkStyle();
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2026-01-01', $workStyle, ['usage_start_date' => '2026-04-01']);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame([['2026-04-01', 6], ['2026-04-01', 18], ['2027-04-01', 30]], array_map(fn ($c) => [$c['scheduledOn'], $c['nominalServiceMonths']], $candidates));
    }

    // ---- 付与日数の区分 ----

    public function test_proportional_three_day_worker_gets_five_and_six_days_for_the_front_loaded_pair(): void
    {
        $workStyle = $this->createWorkStyleWith(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => null]);
        $this->createRuleWithTables($workStyle, $this->normalTable() + ['proportional_3' => [6 => 5, 18 => 6, 30 => 6, 42 => 8]]);
        $user = $this->userHiredOn('2026-01-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame(
            [['2026-04-01', 6, 5.0, true], ['2026-04-01', 18, 6.0, true], ['2027-04-01', 30, 6.0, true]],
            $this->summarize($candidates),
        );
        $this->assertSame('proportional', $candidates[0]['category']);
    }

    /**
     * @return array<string, array{?float, ?int, ?string}>
     */
    public static function proportionalCategoryCases(): array
    {
        return [
            '週4日' => [4, null, 'proportional_4'],
            '週3日' => [3, null, 'proportional_3'],
            '週2日' => [2, null, 'proportional_2'],
            '週1日' => [1, null, 'proportional_1'],
            '週所定日数が優先される(週2日・年間200日)' => [2, 200, 'proportional_2'],
            '年間216日→4' => [null, 216, 'proportional_4'],
            '年間169日→4' => [null, 169, 'proportional_4'],
            '年間168日→3' => [null, 168, 'proportional_3'],
            '年間121日→3' => [null, 121, 'proportional_3'],
            '年間120日→2' => [null, 120, 'proportional_2'],
            '年間73日→2' => [null, 73, 'proportional_2'],
            '年間72日→1' => [null, 72, 'proportional_1'],
            '年間48日→1' => [null, 48, 'proportional_1'],
            '年間47日は要確認' => [null, 47, null],
            '週0日は要確認' => [0, null, null],
            '週0日は年間日数があっても要確認' => [0, 150, null],
        ];
    }

    #[DataProvider('proportionalCategoryCases')]
    public function test_proportional_grant_category_is_decided_from_weekly_or_annual_scheduled_days(?float $weekly, ?int $annual, ?string $expectedCategory): void
    {
        $workStyle = $this->createWorkStyleWith(['weekly_scheduled_days' => $weekly, 'annual_scheduled_days' => $annual]);
        // 区分ごとに違う日数の表(proportional_N => N日)。引かれた表が分かる。
        $tables = ['normal' => [6 => 10]];
        foreach ([4, 3, 2, 1] as $n) {
            $tables["proportional_{$n}"] = [6 => $n];
        }
        $this->createRuleWithTables($workStyle, $tables);
        $user = $this->userHiredOn('2025-10-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2026-12-31'));

        $this->assertSame('2026-04-01', $candidates[0]['scheduledOn']);

        if ($expectedCategory === null) {
            $this->assertFalse($candidates[0]['isDeterminate']);
            $this->assertSame(0.0, $candidates[0]['candidateGrantDays']);

            return;
        }

        $this->assertTrue($candidates[0]['isDeterminate']);
        $this->assertSame((float) substr($expectedCategory, -1), $candidates[0]['candidateGrantDays']);
    }

    public function test_shift_worker_uses_the_normal_table(): void
    {
        $workStyle = $this->createWorkStyleWith(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => null, 'is_shift_based' => true]);
        $this->createRuleWithTables($workStyle, ['normal' => [6 => 10], 'proportional_3' => [6 => 5]]);
        $user = $this->userHiredOn('2025-10-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2026-12-31'));

        $this->assertSame('shift', $candidates[0]['category']);
        $this->assertSame(10.0, $candidates[0]['candidateGrantDays']);
        $this->assertTrue($candidates[0]['isDeterminate']);
    }

    public function test_missing_grant_category_table_needs_review_without_falling_back_to_other_tables_or_statutory(): void
    {
        // 比例(週3日)の社員だが、ルールには通常の表しか無い。通常の表(10日)にも法定にも切り替えず、要確認。
        $this->seedNormalPolicyUpTo();
        $workStyle = $this->createWorkStyleWith(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => null]);
        $this->createRuleWithTables($workStyle, $this->normalTable());
        $user = $this->userHiredOn('2025-10-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2026-12-31'));

        $this->assertFalse($candidates[0]['isDeterminate']);
        $this->assertSame(0.0, $candidates[0]['candidateGrantDays']);
    }

    public function test_missing_step_for_the_nominal_months_in_the_category_table_needs_review(): void
    {
        // 週3日の表は18か月から。名目6か月の段が無いので、1件目だけ要確認。
        $workStyle = $this->createWorkStyleWith(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => null]);
        $this->createRuleWithTables($workStyle, ['proportional_3' => [18 => 6]]);
        $user = $this->userHiredOn('2026-01-01', $workStyle);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame([false, true, true], array_column($candidates, 'isDeterminate'));
        $this->assertSame([0.0, 6.0, 6.0], array_column($candidates, 'candidateGrantDays'));
    }

    public function test_user_without_work_style_needs_review_even_when_rule_has_tables(): void
    {
        $this->setFiscalYearStart(4, 1);
        // 勤務形態が無い社員は区分を決められない(全社ルールに表があっても要確認)。
        $user = User::factory()->create(['hire_date' => '2025-10-01', 'employment_status' => 'active']);

        $candidates = $this->generator()->candidatesFor($user, Carbon::parse('2020-01-01'), Carbon::parse('2026-12-31'));

        $this->assertNotEmpty($candidates);
        $this->assertFalse($candidates[0]['isDeterminate']);
    }

    public function test_default_global_rule_gives_statutory_days_to_normal_and_proportional_workers(): void
    {
        // マイグレーションの初期の全社ルール(年度はじめ一斉付与・法定の写し)。
        $normal = $this->createWorkStyle();
        $proportional = $this->createWorkStyleWith(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => null]);
        $normalUser = $this->userHiredOn('2026-01-01', $normal);
        $proportionalUser = $this->userHiredOn('2026-01-01', $proportional);

        $normalCandidates = $this->generator()->candidatesFor($normalUser, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));
        $proportionalCandidates = $this->generator()->candidatesFor($proportionalUser, Carbon::parse('2020-01-01'), Carbon::parse('2027-12-31'));

        $this->assertSame([10.0, 11.0, 12.0], array_column($normalCandidates, 'candidateGrantDays'));
        $this->assertSame([5.0, 6.0, 6.0], array_column($proportionalCandidates, 'candidateGrantDays'));
    }
}
