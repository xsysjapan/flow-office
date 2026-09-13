<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Models\PaidLeaveGrantPolicy;
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

    public function test_it_generates_schedule_entries_up_to_one_year_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();

        // 6か月後の記念日(2027-03-13)が「今日から1年以内」に入る入社日を選ぶ。
        $user = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'active']);
        $this->assignWorkStyle($user, $workStyle);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $entries = PaidLeaveScheduleEntry::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('2027-03-13', $entries->first()->scheduled_on->toDateString());
        $this->assertEquals(10.0, (float) $entries->first()->candidate_grant_days);

        Carbon::setTestNow();
    }

    public function test_running_twice_does_not_create_duplicate_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-13'));
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();

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

        $inactive = User::factory()->create(['hire_date' => '2026-09-13', 'employment_status' => 'retired']);
        $this->assignWorkStyle($inactive, $workStyle);

        $noHireDate = User::factory()->create(['hire_date' => null, 'employment_status' => 'active']);

        $this->artisan('paid-leave:roll-schedules')->assertSuccessful();

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $inactive->id)->count());
        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $noHireDate->id)->count());

        Carbon::setTestNow();
    }
}
