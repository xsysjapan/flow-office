<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1か月単位変形労働時間制(work_time_system=monthly_variable)。
 * docs/08-usecases-calendar-shift.md「1か月単位変形労働時間制」参照。
 * .claude/skills/attendance-calc-review 参照。
 */
class MonthlyVariableWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function makeCalendar(): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
    }

    private function makeMonthlyVariableWorkStyle(WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'mv-'.uniqid(), 'name' => '1か月単位変形労働時間制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => true,
            'variable_period_start_day' => 1,
        ]);
    }

    private function makeShiftAssignment(User $user, WorkStyle $workStyle, string $workDate, string $plannedStart, string $plannedEnd, int $plannedBreakMinutes = 60): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$workDate} {$plannedStart}:00",
            'planned_end_at' => "{$workDate} {$plannedEnd}:00",
            'planned_break_minutes' => $plannedBreakMinutes,
        ]);
    }

    public function test_a_preset_nine_hour_day_is_not_overtime_until_it_exceeds_nine_hours(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeMonthlyVariableWorkStyle($calendar);
        $user = User::factory()->create();

        // あらかじめ9時間(10:00〜20:00, 休憩1時間)を所定労働時間として設定した日。
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-01', '09:00', '19:00');

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        // ちょうど9時間(所定通り)働いた場合は残業なし。
        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T19:00:00+09:00',
            'breaks' => [['start' => '2026-06-01T12:00:00+09:00', 'end' => '2026-06-01T13:00:00+09:00']],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        $calculation = $day->refresh()->calculation;
        $this->assertSame(0, $calculation->statutory_overtime_minutes, '所定通り9時間働いても法定時間外にならない');
        $this->assertSame(0, $calculation->non_statutory_overtime_minutes);

        // 10時間働いた場合は所定(9時間)を超えた1時間のみ法定時間外。
        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T20:00:00+09:00',
            'breaks' => [['start' => '2026-06-01T12:00:00+09:00', 'end' => '2026-06-01T13:00:00+09:00']],
            'reason' => 'テストデータ投入(延長)',
        ])->assertOk();

        $calculation = $day->refresh()->calculation;
        $this->assertSame(60, $calculation->statutory_overtime_minutes, '所定9時間を超えた1時間のみ法定時間外');
    }

    public function test_editing_a_shift_plan_is_rejected_once_actual_attendance_exists(): void
    {
        $calendar = $this->makeCalendar();
        $admin = $this->makeAdmin();
        $workStyle = $this->makeMonthlyVariableWorkStyle($calendar);
        $user = User::factory()->create();

        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-01', '09:00', '18:00');

        AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::CLOCKED_OUT, 'source' => 'manual', 'utc_offset_minutes' => 540,
            'actual_start_at' => '2026-06-01 09:00:00', 'actual_end_at' => '2026-06-01 18:00:00',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/employee-shift-assignments/{$shift->id}", [
            'planned_start_at' => '2026-06-01T09:00:00+09:00',
            'planned_end_at' => '2026-06-01T21:00:00+09:00',
            'planned_break_minutes' => 60,
            'reason' => '事後に残業を通常勤務へ振り替えようとする変更(拒否されるべき)',
        ]);

        $response->assertStatus(422);
    }

    public function test_editing_a_shift_plan_beyond_the_period_statutory_cap_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $admin = $this->makeAdmin();
        $workStyle = $this->makeMonthlyVariableWorkStyle($calendar);
        $user = User::factory()->create();

        // 2026年6月(30日間)の法定労働時間総枠 = 40時間 × 30/7 ≈ 171.43時間 = 10285分。
        // 29日間、所定8時間(480分)ずつ設定すると13920分になり、既にこの時点で総枠を超える。
        for ($i = 1; $i <= 29; $i++) {
            $date = sprintf('2026-06-%02d', $i);
            $this->makeShiftAssignment($user, $workStyle, $date, '09:00', '18:00');
        }

        $target = $this->makeShiftAssignment($user, $workStyle, '2026-06-30', '09:00', '12:00', 0);

        $response = $this->actingAs($admin)->putJson("/api/employee-shift-assignments/{$target->id}", [
            'planned_start_at' => '2026-06-30T09:00:00+09:00',
            'planned_end_at' => '2026-06-30T20:00:00+09:00',
            'planned_break_minutes' => 60,
            'reason' => '変形期間の法定労働時間総枠を超える設定(拒否されるべき)',
        ]);

        $response->assertStatus(422);
    }

    public function test_weekly_reference_uses_the_preset_weekly_total_as_the_threshold(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeMonthlyVariableWorkStyle($calendar);
        $user = User::factory()->create();
        $approver = User::factory()->create();

        // 月〜金、あらかじめ1日8.8時間(528分)ずつ設定(週合計44時間)。実働も所定通り。
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05'] as $day) {
            $date = "2026-{$day}";
            $shift = $this->makeShiftAssignment($user, $workStyle, $date, '09:00', '18:48');

            $attendanceDay = AttendanceDay::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'shift_assignment_id' => $shift->id,
                'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
            ]);

            $this->actingAs($user)->putJson("/api/attendance/days/{$attendanceDay->id}", [
                'actual_start_at' => "{$date}T09:00:00+09:00",
                'actual_end_at' => "{$date}T18:48:00+09:00",
                'breaks' => [['start' => "{$date}T12:00:00+09:00", 'end' => "{$date}T13:00:00+09:00"]],
                'reason' => 'テストデータ投入',
            ])->assertOk();
        }

        $response = $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $week = collect($response->json('weekly_overtime_reference'))->firstWhere('week_start_date', '2026-06-01');

        $this->assertSame(0, $week['daily_statutory_overtime_minutes'], 'どの日も所定8.8時間通りのため日次残業なし');
        $this->assertSame(0, $week['weekly_statutory_overtime_minutes'], '週の所定合計44時間まで働いても超過なし');
    }
}
