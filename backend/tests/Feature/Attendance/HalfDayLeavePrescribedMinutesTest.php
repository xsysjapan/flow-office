<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 半休(work_typeが`_am_half`/`_pm_half`)の日は所定労働時間を所定労働時間の半分とする
 * (AttendanceCalculator参照)。全休・通常勤務日・時間単位休暇は変更しない。
 * .claude/skills/attendance-calc-review 参照。
 */
class HalfDayLeavePrescribedMinutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(int $prescribedDailyMinutes = 480): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
    }

    private function recordDay(
        User $user,
        WorkStyle $workStyle,
        string $workDate,
        ?string $actualStart,
        ?string $actualEnd,
        ?string $workType,
    ): AttendanceDay {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => false, 'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $payload = [
            'work_type' => $workType,
            'reason' => 'テストデータ投入',
        ];
        if ($actualStart !== null && $actualEnd !== null) {
            $payload['actual_start_at'] = "{$workDate}T{$actualStart}:00+09:00";
            $payload['actual_end_at'] = "{$workDate}T{$actualEnd}:00+09:00";
        }

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", $payload)->assertOk();

        return $day->refresh();
    }

    public function test_special_leave_pm_half_halves_the_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '12:00', 'special_leave_pm_half');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(240, $response->json('calculation.prescribed_work_minutes'));
        $this->assertSame(180, $response->json('calculation.work_minutes'));
        // 3時間しか働いていないため、半分の所定(240分)にも届かず残業は発生しない。
        $this->assertSame(0, $response->json('calculation.statutory_within_overtime_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_excess_overtime_minutes'));
    }

    public function test_paid_leave_am_half_halves_the_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '13:00', '17:00', 'paid_leave_am_half');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(240, $response->json('calculation.prescribed_work_minutes'));
        $this->assertSame(240, $response->json('calculation.work_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_within_overtime_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_excess_overtime_minutes'));
    }

    public function test_full_day_leave_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', null, null, 'special_leave_full');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    public function test_an_ordinary_working_day_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', null);

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    public function test_hourly_leave_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', 'paid_leave_hourly');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    /**
     * 月次確認画面の集計(MonthlyOvertimeCalculator::calculateCategoryTotals)の
     * prescribed_work_minutes合計に、半休日の按分後の値が正しく反映されることを確認する。
     */
    public function test_monthly_totals_reflect_the_halved_prescribed_minutes_for_a_half_day_leave_day(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', null);
        $this->recordDay($user, $workStyle, '2026-08-04', '09:00', '12:00', 'special_leave_pm_half');

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-08')->assertOk();
        $totals = $response->json('monthly_calculation_totals');

        $this->assertSame(480 + 240, $totals['prescribed_work_minutes']);
    }
}
