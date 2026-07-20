<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 退勤時の標準休憩自動補完(work_styles.auto_break_enabled)。ClockOutHandler参照。
 * 実際に打刻・編集された休憩が1件でもある日には絶対に手を加えないことを重点的に確認する。
 */
class AttendanceAutoBreakTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createWorkStyleAndAssignment(User $employee, Carbon $today, bool $autoBreakEnabled): WorkStyle
    {
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00',
            'default_end_time' => '18:00',
            'default_break_minutes' => 60,
            'default_break_start_time' => '12:00',
            'default_break_end_time' => '13:00',
            'auto_break_enabled' => $autoBreakEnabled,
            'is_shift_based' => false,
        ]);

        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id,
            'work_date' => $today->toDateString(),
            'work_style_id' => $workStyle->id,
            'day_type' => 'weekday',
            'is_working_day' => true,
            'is_legal_holiday' => false,
            'is_company_holiday' => false,
        ]);

        return $workStyle;
    }

    public function test_auto_break_is_inserted_when_all_conditions_are_met(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $this->createWorkStyleAndAssignment($employee, $today, true);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->first();
        $this->assertNotNull($day);
        $this->assertSame(1, $day->breaks()->count());

        $break = $day->breaks()->first();
        $this->assertSame('12:00:00', $break->break_start_at->format('H:i:s'));
        $this->assertSame('13:00:00', $break->break_end_at->format('H:i:s'));

        // 9:00〜18:00(9時間)から自動補完された休憩1時間を差し引いた480分が労働時間になる。
        $this->assertSame(480, $day->calculation->work_minutes);
    }

    public function test_auto_break_is_not_inserted_when_worked_span_is_less_than_six_hours(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $this->createWorkStyleAndAssignment($employee, $today, true);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        // 9:00〜14:00は5時間しか働いていないため、6時間未満で自動補完の対象外。
        Carbon::setTestNow($today->copy()->setTime(14, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->first();
        $this->assertSame(0, $day->breaks()->count());
    }

    public function test_auto_break_is_not_inserted_when_the_standard_break_window_does_not_fit_inside_the_worked_span(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $this->createWorkStyleAndAssignment($employee, $today, true);

        // 12:30に出勤するため、標準休憩(12:00〜13:00)の開始が実働時間より前になり、
        // 実働時間内に収まらない(実働は12:30〜20:00の7.5時間で6時間以上ではある)。
        Carbon::setTestNow($today->copy()->setTime(12, 30));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        Carbon::setTestNow($today->copy()->setTime(20, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->first();
        $this->assertSame(0, $day->breaks()->count());
    }

    public function test_auto_break_is_not_inserted_when_a_break_was_already_recorded_manually(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $this->createWorkStyleAndAssignment($employee, $today, true);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        // 実際に30分だけ休憩を打刻した(標準休憩12:00〜13:00とは異なる)。
        Carbon::setTestNow($today->copy()->setTime(12, 0));
        $this->actingAs($employee)->postJson('/api/attendance/break/start')->assertSuccessful();
        Carbon::setTestNow($today->copy()->setTime(12, 30));
        $this->actingAs($employee)->postJson('/api/attendance/break/end')->assertSuccessful();

        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->first();
        $this->assertSame(1, $day->breaks()->count(), '手動で記録済みの休憩に加えて自動補完してはいけない');

        $break = $day->breaks()->first();
        $this->assertSame('12:00:00', $break->break_start_at->format('H:i:s'));
        $this->assertSame('12:30:00', $break->break_end_at->format('H:i:s'));
    }

    public function test_auto_break_is_not_inserted_when_the_work_style_has_auto_break_disabled(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $this->createWorkStyleAndAssignment($employee, $today, false);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->first();
        $this->assertSame(0, $day->breaks()->count());
    }
}
