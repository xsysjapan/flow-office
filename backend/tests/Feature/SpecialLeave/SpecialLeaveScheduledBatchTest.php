<?php

namespace Tests\Feature\SpecialLeave;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Commands\GrantScheduledSpecialLeave;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantRule;
use App\Models\SpecialLeaveType;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 特別休暇種別ごとの自動付与ルールに基づく自動付与バッチ。
 * GrantScheduledPaidLeaveHandlerと同じ考え方の判定ロジックを検証する。
 */
class SpecialLeaveScheduledBatchTest extends TestCase
{
    use RefreshDatabase;

    private function createRuleWithSteps(SpecialLeaveType $type, ?int $expiresAfterMonths = null): SpecialLeaveGrantRule
    {
        $rule = SpecialLeaveGrantRule::query()->create([
            'special_leave_type_id' => $type->id,
            'name' => '誕生日休暇ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 0, 'grant_cycle_months' => 12,
            'expires_after_months' => $expiresAfterMonths, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 0, 'grant_days' => 1]);

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

    private function seedAttendanceHistory(User $user, Carbon $today, int $scheduledDays, int $attendedDays): void
    {
        $workStyle = $this->createWorkStyle();

        for ($i = 0; $i < $scheduledDays; $i++) {
            $date = $today->copy()->subDays($i * 7);
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

    public function test_it_grants_special_leave_on_the_exact_hire_anniversary_with_an_expiry_computed_from_the_rule(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->createRuleWithSteps($type, expiresAfterMonths: 6);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 4);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(1, $grantedIds);
        $grant = SpecialLeaveGrant::query()->findOrFail($grantedIds[0]);
        $this->assertSame($employee->id, $grant->user_id);
        $this->assertSame($type->id, $grant->special_leave_type_id);
        $this->assertEquals(1.0, (float) $grant->granted_days);
        $this->assertSame('2027-02-10', $grant->expires_on->toDateString());
    }

    public function test_it_grants_special_leave_with_no_expiry_when_the_rule_has_none(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => 'リフレッシュ休暇', 'is_active' => true]);
        $this->createRuleWithSteps($type, expiresAfterMonths: null);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(1, $grantedIds);
        $grant = SpecialLeaveGrant::query()->findOrFail($grantedIds[0]);
        $this->assertNull($grant->expires_on);
    }

    public function test_it_does_not_grant_on_a_non_anniversary_day(): void
    {
        $today = Carbon::parse('2026-08-11');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->createRuleWithSteps($type);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }

    public function test_it_does_not_grant_twice_for_the_same_day(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->createRuleWithSteps($type);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));
        $secondRun = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(0, $secondRun);
        $this->assertSame(1, SpecialLeaveGrant::query()->where('user_id', $employee->id)->count());
    }

    public function test_it_skips_an_inactive_special_leave_type_even_if_its_rule_is_active(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => '廃止済み休暇', 'is_active' => false]);
        $this->createRuleWithSteps($type);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }

    public function test_it_skips_when_attendance_rate_is_below_the_rules_threshold(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create(['hire_date' => '2025-08-10']);
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->createRuleWithSteps($type);
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 2); // 40% < 80%

        $grantedIds = app(CommandBus::class)->dispatch(new GrantScheduledSpecialLeave($today->toDateString()));

        $this->assertCount(0, $grantedIds);
    }
}
