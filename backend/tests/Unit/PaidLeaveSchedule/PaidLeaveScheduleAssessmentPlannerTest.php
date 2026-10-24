<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlanner;
use App\Models\LeaveAttendanceRateDay;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 付与予定1行の出勤率判定の内容(期間・みなし出勤・閾値)の決定
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点5・10)。
 * 年は2026年の前提で固定して書く。出勤率ビュー(`leave_attendance_rate_days`)の行を直接作って確かめる。
 */
class PaidLeaveScheduleAssessmentPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function planner(): PaidLeaveScheduleAssessmentPlanner
    {
        return app(PaidLeaveScheduleAssessmentPlanner::class);
    }

    /**
     * 全社ルールを、このテストの値(閾値・初回の月数・判定周期)の1件だけにする。
     */
    private function globalRule(float $minRate = 80, int $firstAfter = 6, int $cycle = 12): PaidLeaveGrantRule
    {
        PaidLeaveGrantRule::query()->update(['is_active' => false]);

        return PaidLeaveGrantRule::query()->create([
            'name' => 'テスト全社ルール',
            'work_style_id' => null,
            'min_attendance_rate' => $minRate,
            'first_grant_after_months' => $firstAfter,
            'grant_cycle_months' => $cycle,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
            'is_active' => true,
        ]);
    }

    private function user(?string $hireDate, ?string $usageStartDate = null): User
    {
        return User::factory()->create(['hire_date' => $hireDate, 'usage_start_date' => $usageStartDate]);
    }

    private function entry(User $user, string $scheduledOn, int $months, string $status = PaidLeaveScheduleEntry::STATUS_SCHEDULED): PaidLeaveScheduleEntry
    {
        return PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => $scheduledOn,
            'category' => 'normal',
            'candidate_grant_days' => 10,
            'nominal_service_months' => $months,
            'status' => $status,
        ]);
    }

    /**
     * 開始日から連続した日数分の所定労働日を作り、先頭の$attended日を出勤にする。
     */
    private function workingDays(User $user, string $from, int $count, int $attended): void
    {
        $date = Carbon::parse($from);
        for ($i = 0; $i < $count; $i++, $date->addDay()) {
            LeaveAttendanceRateDay::query()->create([
                'user_id' => $user->id,
                'work_date' => $date->toDateString(),
                'is_working_day' => true,
                'attended' => $i < $attended,
                'full_leave_kinds' => [],
                'partial_leave_kinds' => [],
            ]);
        }
    }

    public function test_early_first_grant_deems_the_shortened_period_as_attended(): void
    {
        $this->globalRule();
        // 入社2026-01-10、年度開始4/1、初回6か月。実期間1/10〜3/31(81日)、短縮期間4/1〜7/9(100日)。
        $user = $this->user('2026-01-10');
        $entry = $this->entry($user, '2026-04-01', 6);
        $this->workingDays($user, '2026-01-10', 55, 50);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2026-01-10', $plan->periodStart);
        $this->assertSame('2026-03-31', $plan->periodEnd);
        $this->assertSame(55, $plan->denominatorDays);
        $this->assertSame(50, $plan->attendanceDays);
        // みなし日数 = round(55 × 100 / 81) = round(67.9) = 68。
        $this->assertSame(68, $plan->deemedAttendanceDays);
        // 出勤率 = (50 + 68) / (55 + 68) = 95.9%。
        $this->assertEqualsWithDelta(95.935, $plan->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $plan->automaticResult);
    }

    public function test_deemed_attendance_can_still_leave_the_entry_not_eligible(): void
    {
        $this->globalRule();
        $user = $this->user('2026-01-10');
        $entry = $this->entry($user, '2026-04-01', 6);
        // 所定55日で出勤10日。みなし68日を加えても(10+68)/(55+68)=63.4%で80%未満。
        $this->workingDays($user, '2026-01-10', 55, 10);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame(68, $plan->deemedAttendanceDays);
        $this->assertEqualsWithDelta(63.415, $plan->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $plan->automaticResult);
    }

    public function test_first_grant_that_is_not_early_has_no_deemed_days(): void
    {
        $this->globalRule();
        // 入社2025-10-01の半年後は2026-04-01。年度開始日と同じ日なので前倒しではない。
        $user = $this->user('2025-10-01');
        $entry = $this->entry($user, '2026-04-01', 6);
        $this->workingDays($user, '2025-10-01', 100, 90);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2025-10-01', $plan->periodStart);
        $this->assertSame('2026-03-31', $plan->periodEnd);
        $this->assertSame(0, $plan->deemedAttendanceDays);
        $this->assertSame(100, $plan->denominatorDays);
        $this->assertEqualsWithDelta(90.0, $plan->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $plan->automaticResult);
    }

    public function test_later_grant_starts_at_the_previous_scheduled_date_and_deems_the_shortened_period(): void
    {
        $this->globalRule();
        $user = $this->user('2025-10-01');
        $this->entry($user, '2026-04-01', 6, PaidLeaveScheduleEntry::STATUS_GRANTED);
        // 年度開始日を変えて前回から9か月で次が来た場合。
        // 実期間2026-04-01〜2026-12-31(275日)、本来の基準日2027-04-01、短縮期間2027-01-01〜2027-03-31(90日)。
        $entry = $this->entry($user, '2027-01-01', 18);
        $this->workingDays($user, '2026-04-01', 100, 90);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2026-04-01', $plan->periodStart);
        $this->assertSame('2026-12-31', $plan->periodEnd);
        // みなし日数 = round(100 × 90 / 275) = round(32.7) = 33。
        $this->assertSame(33, $plan->deemedAttendanceDays);
        // (90 + 33) / (100 + 33) = 92.5%。
        $this->assertEqualsWithDelta(92.481, $plan->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $plan->automaticResult);
    }

    public function test_later_grant_on_the_natural_date_has_no_deemed_days(): void
    {
        $this->globalRule();
        $user = $this->user('2025-10-01');
        $this->entry($user, '2026-04-01', 6, PaidLeaveScheduleEntry::STATUS_GRANTED);
        // 前回2026-04-01の12か月後の2027-04-01。期間は2026-04-01〜2027-03-31。
        $entry = $this->entry($user, '2027-04-01', 18);
        $this->workingDays($user, '2026-04-01', 10, 9);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2026-04-01', $plan->periodStart);
        $this->assertSame('2027-03-31', $plan->periodEnd);
        $this->assertSame(0, $plan->deemedAttendanceDays);
        $this->assertEqualsWithDelta(90.0, $plan->attendanceRate, 0.001);
    }

    public function test_cancelled_entries_are_not_the_previous_entry(): void
    {
        $this->globalRule();
        $user = $this->user('2025-10-01');
        $this->entry($user, '2026-04-01', 6, PaidLeaveScheduleEntry::STATUS_CANCELLED);
        $this->entry($user, '2026-10-01', 6, PaidLeaveScheduleEntry::STATUS_GRANTED);
        $entry = $this->entry($user, '2027-10-01', 18);

        $plan = $this->planner()->plan($entry, $user);

        // 取消の行は前回に数えない。前回は2026-10-01。
        $this->assertSame('2026-10-01', $plan->periodStart);
        $this->assertSame('2027-09-30', $plan->periodEnd);
    }

    public function test_it_uses_the_global_rule_threshold_for_an_employee_with_a_work_style(): void
    {
        $this->globalRule(minRate: 90);
        $workStyle = WorkStyle::query()->create([
            'code' => 'NORMAL',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
        $user = $this->user('2025-10-01');
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id, 'year_month' => '2025-10', 'work_style_id' => $workStyle->id, 'assigned_by_user_id' => $user->id,
        ]);
        $entry = $this->entry($user, '2026-04-01', 6);
        // 出勤率85%。既定の80%なら付与対象だが、全社ルールの閾値90%では対象外。
        $this->workingDays($user, '2025-10-01', 100, 85);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertEqualsWithDelta(85.0, $plan->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $plan->automaticResult);
    }

    public function test_the_period_ends_the_day_before_the_scheduled_date(): void
    {
        $this->globalRule();
        $user = $this->user('2025-10-01');
        $entry = $this->entry($user, '2026-04-01', 6);
        // 前日(3/31)の分だけが対象。付与予定日(4/1)の所定労働日は期間に入らない。
        $this->workingDays($user, '2026-03-31', 2, 2);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2026-03-31', $plan->periodEnd);
        $this->assertSame(1, $plan->denominatorDays);
    }

    public function test_an_entry_with_neither_first_nor_previous_uses_the_cycle_before_the_scheduled_date(): void
    {
        $this->globalRule();
        // 入社2020-01-01の初回(2020-07-01)は過ぎていて、前の行も無い(利用開始日の都合で作られていない)行。
        $user = $this->user('2020-01-01');
        $entry = $this->entry($user, '2026-04-01', 78);
        $this->workingDays($user, '2025-04-01', 10, 10);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame('2025-04-01', $plan->periodStart);
        $this->assertSame('2026-03-31', $plan->periodEnd);
        $this->assertSame(0, $plan->deemedAttendanceDays);
        $this->assertSame(10, $plan->denominatorDays);
    }

    public function test_the_second_of_a_bundled_pair_is_eligible_even_when_the_first_is_not(): void
    {
        $this->globalRule();
        $user = $this->user('2026-01-10');
        $first = $this->entry($user, '2026-04-01', 6);
        $second = $this->entry($user, '2026-04-01', 18);
        $this->workingDays($user, '2026-01-10', 55, 10);

        $firstPlan = $this->planner()->plan($first, $user);
        $secondPlan = $this->planner()->plan($second, $user);

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $firstPlan->automaticResult);

        // 2件目は判定を共有せず、全出勤とみなして付与対象。期間は付与予定日から本来の基準日(入社日+18か月=2027-07-10)の前日まで。
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $secondPlan->automaticResult);
        $this->assertSame('2026-04-01', $secondPlan->periodStart);
        $this->assertSame('2027-07-09', $secondPlan->periodEnd);
        $this->assertSame(0, $secondPlan->denominatorDays);
        $this->assertSame(0, $secondPlan->attendanceDays);
        $this->assertSame(0, $secondPlan->deemedAttendanceDays);
        $this->assertEqualsWithDelta(100.0, $secondPlan->attendanceRate, 0.001);
    }

    public function test_the_second_of_a_pair_is_not_bundled_when_the_first_is_cancelled(): void
    {
        $this->globalRule();
        $user = $this->user('2026-01-10');
        $this->entry($user, '2026-04-01', 6, PaidLeaveScheduleEntry::STATUS_CANCELLED);
        $second = $this->entry($user, '2026-04-01', 18);

        $plan = $this->planner()->plan($second, $user);

        // 組ではないので、通常の行として判定する(所定労働日が無いので要確認)。
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $plan->automaticResult);
        $this->assertSame('2026-03-31', $plan->periodEnd);
    }

    public function test_usage_start_date_rule_needs_review_unless_migrated_data_exists(): void
    {
        $this->globalRule();
        // 期間の始まり(入社日2025-10-01)が利用開始日(2026-01-01)より前。
        $user = $this->user('2025-10-01', '2026-01-01');
        $entry = $this->entry($user, '2026-04-01', 6);
        $this->workingDays($user, '2025-10-01', 100, 100);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $plan->automaticResult);
        $this->assertSame('usage_start_date_boundary', $plan->needsReviewReason);
        $this->assertSame(0, $plan->deemedAttendanceDays);

        // 移行データ(carried_over)の付与があれば、判定する。
        PaidLeaveGrant::query()->create([
            'user_id' => $user->id,
            'granted_on' => '2025-12-01',
            'expires_on' => '2027-12-01',
            'granted_days' => 10,
            'allocated_days' => 0,
            'used_days' => 0,
            'remaining_days' => 10,
            'source' => 'carried_over',
            'status' => 'active',
        ]);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $plan->automaticResult);
    }

    public function test_usage_start_date_rule_still_applies_to_the_second_of_a_bundled_pair(): void
    {
        $this->globalRule();
        // 付与予定日(2026-04-01)が利用開始日(2026-06-01)より前の組(手動で作られた行など)。
        $user = $this->user('2026-01-10', '2026-06-01');
        $this->entry($user, '2026-04-01', 6);
        $second = $this->entry($user, '2026-04-01', 18);

        $plan = $this->planner()->plan($second, $user);

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $plan->automaticResult);
        $this->assertNull($plan->attendanceRate);
    }

    public function test_it_defaults_to_80_percent_when_no_rule_applies(): void
    {
        PaidLeaveGrantRule::query()->update(['is_active' => false]);
        $user = $this->user('2025-10-01');
        $entry = $this->entry($user, '2026-04-01', 6);
        $this->workingDays($user, '2025-10-01', 10, 8);

        $plan = $this->planner()->plan($entry, $user);

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $plan->automaticResult);
        $this->assertSame('v2', AttendanceRateAssessor::POLICY_VERSION);
    }
}
