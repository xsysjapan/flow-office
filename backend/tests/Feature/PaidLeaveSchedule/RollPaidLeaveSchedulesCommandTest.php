<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec.md 実装対象Phase C・論点6: `paid-leave:roll-schedules`(旧`paid-leave:grant-scheduled`を
 * 置換)のべき等性・1年先までの生成保証を検証する。
 *
 * docs/changesets/20260914-port-to-pr112/spec.md 移植により、マイグレーションで
 * 全社共通(work_style_id=null)の一斉付与ルールが1件シードされるため、以降のテストは
 * work_style固有の有効ルール(anniversary型)を明示的に作成して優先させ、
 * 従来通りの周年サイクル生成であることを検証する。全社共通ルールのみが効くケース
 * (ルール未作成)・ルールが全く無いケースは別テストで検証する。
 */
class RollPaidLeaveSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createNormalWorkStyle(): WorkStyle
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

    private function seedNormalPolicy(): void
    {
        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            PaidLeaveGrantPolicy::query()->create([
                'version' => 'v1', 'continuous_service_months' => $months, 'grant_days' => $days,
                'effective_from' => '2020-01-01', 'is_active' => true,
            ]);
        }
    }

    /**
     * work_style固有の周年サイクルルールを、法定Policyと同じstepsで作成する
     * (全社共通の一斉付与ルールより優先されることの確認も兼ねる)。
     */
    private function createAnniversaryRuleFor(WorkStyle $workStyle): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '通常勤務(周年)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);

        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            $rule->steps()->create(['continuous_service_months' => $months, 'grant_days' => $days]);
        }

        return $rule;
    }

    public function test_it_generates_schedule_entries_up_to_one_year_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        // 6か月後の記念日(2027-03-13)が「今日から1年以内」に入る入社日を選ぶ。
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('2027-03-13', $entries->first()->scheduled_on->toDateString());
        $this->assertEquals(10.0, (float) $entries->first()->candidate_grant_days);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $entries->first()->status);

        Carbon::setTestNow();
    }

    public function test_running_twice_does_not_create_duplicate_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();
        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(1, PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_it_skips_inactive_employees_and_employees_without_hire_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $inactive = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'retired']);
        $this->assignWorkStyle($inactive, $workStyle);

        $noHireDate = User::factory()->create(['hire_date' => null, 'employment_status' => 'active']);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $inactive->id)->count());
        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $noHireDate->id)->count());

        Carbon::setTestNow();
    }

    public function test_it_skips_users_with_auto_grant_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $user = User::factory()->create([
            'hire_date' => '2026-09-13',
            'employment_status' => 'active',
            'paid_leave_auto_grant_enabled' => false,
        ]);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_it_generates_no_entries_when_no_active_rule_matches_the_user(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();

        // 全社共通の一斉付与ルールを明示的に無効化し、work_style固有ルールも作らない。
        PaidLeaveGrantRule::query()->whereNull('work_style_id')->update(['is_active' => false]);

        $workStyle = $this->createNormalWorkStyle();
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_entry_is_flagged_needs_review_when_rule_has_no_step_for_the_tenure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $workStyle = $this->createNormalWorkStyle();

        // ステップを1つも持たないルール = 対象月数をカバーするステップが存在しない。
        PaidLeaveGrantRule::query()->create([
            'name' => 'ステップ未整備ルール',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NEEDS_REVIEW, $entries->first()->status);

        Carbon::setTestNow();
    }

    public function test_usage_start_date_guard_skips_candidates_before_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        // 6ヶ月後(2027-03-13)より後、次の周年(2028-03-13)より前のusage_start_dateを設定する。
        $user = User::factory()->create([
            'hire_date' => '2026-09-13',
            'employment_status' => 'active',
            'usage_start_date' => '2027-06-01',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        $to = Carbon::parse('2026-09-13')->addYear();
        $this->assertTrue(Carbon::parse('2027-03-13')->lt($to));

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(
            0,
            PaidLeaveScheduleEntry::query()
                ->where('user_id', $user->id)
                ->where('scheduled_on', '2027-03-13')
                ->count(),
        );

        Carbon::setTestNow();
    }
}
