<?php

namespace Tests\Feature\Attendance;

use App\Models\EmployeeShiftAssignment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日次勤怠の入力画面(未入力の日)を開いた際の初期値。打刻(丸め含む)→勤務予定(休憩を含む)→
 * システムの初期設定、の優先順位で提案されることを確認する(docs/07-usecases-attendance.md参照)。
 */
class AttendanceDayDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_reflect_punches_rounded_to_the_work_styles_rounding_unit(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'rounding_unit_minutes' => 15,
            'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        // clock_outが無いため attendance_days には反映されない(参考情報のまま)。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:53:00+09:00");
        $this->recordPunch($employee, $workDate, 'break_start', "{$workDate}T12:02:00+09:00");
        $this->recordPunch($employee, $workDate, 'break_end', "{$workDate}T12:58:00+09:00");

        $response = $this->actingAs($employee)->getJson(
            "/api/attendance/day-defaults?user_id={$employee->id}&work_date={$workDate}"
        );

        $response->assertOk();
        $response->assertJsonPath('source', 'punch');
        // 15分単位への四捨五入: 08:53→09:00, 12:02→12:00, 12:58→13:00。
        $response->assertJsonPath('actual_start_at', "{$workDate}T09:00:00+09:00");
        $response->assertJsonPath('breaks.0.start', "{$workDate}T12:00:00+09:00");
        $response->assertJsonPath('breaks.0.end', "{$workDate}T13:00:00+09:00");
    }

    public function test_defaults_reflect_the_schedule_including_its_break_when_there_are_no_punches(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$workDate} 09:00:00", 'planned_end_at' => "{$workDate} 18:00:00",
            'planned_break_minutes' => 60,
            'planned_break_start_at' => "{$workDate} 12:00:00", 'planned_break_end_at' => "{$workDate} 13:00:00",
        ]);

        $response = $this->actingAs($employee)->getJson(
            "/api/attendance/day-defaults?user_id={$employee->id}&work_date={$workDate}"
        );

        $response->assertOk();
        $response->assertJsonPath('source', 'schedule');
        $response->assertJsonPath('actual_start_at', "{$workDate}T09:00:00+09:00");
        $response->assertJsonPath('actual_end_at', "{$workDate}T18:00:00+09:00");
        $response->assertJsonPath('breaks.0.start', "{$workDate}T12:00:00+09:00");
        $response->assertJsonPath('breaks.0.end', "{$workDate}T13:00:00+09:00");
    }

    public function test_defaults_fall_back_to_the_default_work_styles_standard_schedule(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'default_break_start_time' => '12:00', 'default_break_end_time' => '13:00',
            'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        $response = $this->actingAs($employee)->getJson(
            "/api/attendance/day-defaults?user_id={$employee->id}&work_date={$workDate}"
        );

        $response->assertOk();
        $response->assertJsonPath('source', 'system_default');
        $response->assertJsonPath('actual_start_at', "{$workDate}T09:00:00+09:00");
        $response->assertJsonPath('actual_end_at', "{$workDate}T18:00:00+09:00");
        $response->assertJsonPath('breaks.0.start', "{$workDate}T12:00:00+09:00");
        $response->assertJsonPath('breaks.0.end', "{$workDate}T13:00:00+09:00");
    }

    public function test_requesting_another_users_defaults_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(
            "/api/attendance/day-defaults?user_id={$employee->id}&work_date=2026-07-09"
        )->assertForbidden();
    }

    private function recordPunch(User $user, string $workDate, string $punchType, string $punchedAt): void
    {
        $this->actingAs($user)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => $punchType,
            'punched_at' => $punchedAt,
            'source' => 'web',
        ])->assertSuccessful();
    }
}
