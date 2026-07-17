<?php

namespace Tests\Feature\PaidLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\GrantScheduledPaidLeave;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-P002: 有給を自動付与する(バッチ) / UC-P005: 有給消滅警告を出す(バッチ) /
 * UC-P006: 年5日取得義務を警告する(バッチ)。
 */
class PaidLeaveScheduledBatchTest extends TestCase
{
    use RefreshDatabase;

    private function createRuleWithSteps(): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '正社員標準', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['continuous_service_months' => 18, 'grant_days' => 11]);

        return $rule;
    }

    private function createWorkStyle(): WorkStyle
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);

        return WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    /**
     * 直近12か月ぶん、指定した出勤率を満たす勤務予定・勤務実績を作成する。
     */
    private function seedAttendanceHistory(User $user, Carbon $today, int $scheduledDays, int $attendedDays): void
    {
        $workStyle = $this->createWorkStyle();

        for ($i = 0; $i < $scheduledDays; $i++) {
            $date = $today->copy()->subDays($i * 7); // 週1日ずつ過去に遡って作成
            EmployeeShiftAssignment::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(), 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);

            if ($i < $attendedDays) {
                AttendanceDay::query()->create([
                    'user_id' => $user->id, 'work_date' => $date->toDateString(),
                    'status' => 'clocked_out', 'source' => 'live',
                ]);
            }
        }
    }

    public function test_it_grants_paid_leave_on_the_exact_hire_anniversary_when_attendance_rate_is_met(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 4);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));

        $this->assertCount(1, $grantedIds);
        $grant = PaidLeaveGrant::query()->findOrFail($grantedIds[0]);
        $this->assertSame($employee->id, $grant->user_id);
        $this->assertEquals(10.0, (float) $grant->granted_days);
        $this->assertSame('2026-08-10', $grant->granted_on->toDateString());
        $this->assertSame('2028-08-10', $grant->expires_on->toDateString());
    }

    public function test_it_does_not_grant_on_a_non_anniversary_day(): void
    {
        $today = Carbon::parse('2026-08-11'); // 記念日(08-10)から1日ずれている
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }

    public function test_it_does_not_grant_twice_for_the_same_day(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));
        $secondRun = app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));

        $this->assertCount(0, $secondRun);
        $this->assertSame(1, PaidLeaveGrant::query()->where('user_id', $employee->id)->count());
    }

    public function test_it_skips_when_attendance_rate_is_below_the_rules_threshold(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 2); // 40% < 80%

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }

    public function test_it_skips_when_there_is_no_schedule_history_to_verify_attendance(): void
    {
        $today = Carbon::parse('2026-08-10');
        User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledPaidLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }

    public function test_warn_expiring_notifies_and_marks_grants_within_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        $expiringSoon = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-10-01',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);
        $expiringLater = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-08-10', 'expires_on' => '2028-08-10',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull($expiringSoon->refresh()->expiry_warned_at);
        $this->assertNull($expiringLater->refresh()->expiry_warned_at);
    }

    public function test_warn_expiring_does_not_renotify_an_already_warned_grant(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-10-01',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
            'expiry_warned_at' => Carbon::parse('2026-08-01'),
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(0, $count);
    }

    private function createUsage(User $employee, PaidLeaveGrant $grant, float $days, string $date): PaidLeaveUsage
    {
        $day = AttendanceDay::query()->create([
            'user_id' => $employee->id, 'work_date' => $date, 'status' => 'clocked_out', 'source' => 'manual',
        ]);
        $request = PaidLeaveRequest::query()->create([
            'user_id' => $employee->id, 'approver_user_id' => $employee->id, 'status' => 'approved',
            'leave_type' => 'full', 'target_date' => $date, 'requested_days' => $days,
        ]);

        return PaidLeaveUsage::query()->create([
            'user_id' => $employee->id, 'attendance_day_id' => $day->id,
            'paid_leave_grant_id' => $grant->id, 'paid_leave_request_id' => $request->id,
            'used_on' => $date, 'used_days' => $days, 'usage_type' => 'full',
        ]);
    }

    public function test_warn_five_day_obligation_warns_when_usage_is_insufficient_near_the_deadline(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-09-01', 'expires_on' => '2027-08-31',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);
        $this->createUsage($employee, $grant, 2, '2026-06-01');

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull($grant->refresh()->five_day_obligation_warned_at);
    }

    public function test_warn_five_day_obligation_skips_when_five_days_are_already_used(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-09-01', 'expires_on' => '2027-08-31',
            'granted_days' => 10, 'used_days' => 5, 'remaining_days' => 5,
        ]);
        $this->createUsage($employee, $grant, 5, '2026-06-01');

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }

    public function test_warn_five_day_obligation_skips_grants_outside_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        // 義務期限(付与日+1年)が2027-08-31で、警告ウィンドウ(60日前)にまだ入っていない
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2026-08-31', 'expires_on' => '2028-08-31',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }
}
