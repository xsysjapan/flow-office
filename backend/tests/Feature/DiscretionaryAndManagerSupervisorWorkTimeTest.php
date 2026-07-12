<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 裁量労働制(work_time_system=discretionary)・管理監督者(work_time_system=manager_supervisor)。
 * docs/08-usecases-calendar-shift.md、docs/07-usecases-attendance.md「裁量労働制・管理監督者」参照。
 * .claude/skills/attendance-calc-review 参照。
 */
class DiscretionaryAndManagerSupervisorWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCalendar(): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
    }

    private function makeDiscretionaryWorkStyle(WorkCalendar $calendar, int $deemedDailyMinutes = 540): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'disc-'.uniqid(), 'name' => '裁量労働制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_DISCRETIONARY,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'deemed_daily_minutes' => $deemedDailyMinutes,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function makeManagerSupervisorWorkStyle(WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'mgr-'.uniqid(), 'name' => '管理監督者',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_MANAGER_SUPERVISOR,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function makeShiftAssignment(User $user, WorkStyle $workStyle, string $workDate, bool $isLegalHoliday = false): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => $isLegalHoliday ? 'legal_holiday' : 'weekday',
            'is_working_day' => ! $isLegalHoliday,
            'is_legal_holiday' => $isLegalHoliday,
            'is_company_holiday' => false,
            'planned_break_minutes' => 60,
        ]);
    }

    private function editDay(
        User $user,
        AttendanceDay $day,
        string $workDate,
        ?string $actualStart,
        ?string $actualEnd,
        ?array $break = ['start' => '12:00', 'end' => '13:00'],
    ): TestResponse {
        $breaks = $break === null ? [] : [[
            'start' => "{$workDate}T{$break['start']}:00+09:00",
            'end' => "{$workDate}T{$break['end']}:00+09:00",
        ]];

        return $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => $actualStart !== null ? "{$workDate}T{$actualStart}:00+09:00" : null,
            'actual_end_at' => $actualEnd !== null ? "{$workDate}T{$actualEnd}:00+09:00" : null,
            'breaks' => $breaks,
            'reason' => 'テストデータ投入',
        ])->assertOk();
    }

    public function test_discretionary_worker_is_paid_the_deemed_time_regardless_of_actual_hours_worked(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeDiscretionaryWorkStyle($calendar, deemedDailyMinutes: 540); // 9時間
        $user = User::factory()->create();
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-01');

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        // 実際には10時間(休憩1時間を除く)働いたが、給与計算上はみなし9時間のまま。
        $this->editDay($user, $day, '2026-06-01', '09:00', '20:00');

        $calculation = $day->refresh()->calculation;
        $this->assertSame(600, $calculation->actual_work_minutes, '実労働時間は健康管理用にそのまま記録する');
        $this->assertSame(540, $calculation->deemed_work_minutes);
        $this->assertSame(540, $calculation->payroll_work_minutes, '給与計算上はみなし時間を採用する');
        $this->assertSame(60, $calculation->statutory_overtime_minutes, 'みなし9時間のうち8時間を超えた1時間のみ法定時間外');
        $this->assertSame(0, $calculation->non_statutory_overtime_minutes);
    }

    public function test_discretionary_worker_does_not_need_to_clock_in_for_payroll_to_apply(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeDiscretionaryWorkStyle($calendar, deemedDailyMinutes: 540);
        $user = User::factory()->create();
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-01');

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        // 出退勤を一切記録しない(打刻不要)。
        $this->editDay($user, $day, '2026-06-01', null, null, break: null);

        $calculation = $day->refresh()->calculation;
        $this->assertSame(0, $calculation->actual_work_minutes);
        $this->assertSame(540, $calculation->payroll_work_minutes, '打刻が無くてもみなし時間が給与計算上の労働時間になる');
        $this->assertSame(60, $calculation->statutory_overtime_minutes);
    }

    public function test_discretionary_worker_legal_holiday_work_is_calculated_from_actual_time_not_deemed_time(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeDiscretionaryWorkStyle($calendar, deemedDailyMinutes: 540);
        $user = User::factory()->create();
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-07', isLegalHoliday: true);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-07', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $this->editDay($user, $day, '2026-06-07', '10:00', '14:00', break: null);

        $calculation = $day->refresh()->calculation;
        $this->assertNull($calculation->deemed_work_minutes, '法定休日はみなし労働の対象日ではない');
        $this->assertSame(240, $calculation->legal_holiday_work_minutes, '法定休日労働は実際の時刻から計算する');
        $this->assertSame(240, $calculation->payroll_work_minutes, 'みなしが適用されない日は実労働時間を給与計算に使う');
        $this->assertSame(0, $calculation->statutory_overtime_minutes);
    }

    public function test_manager_supervisor_is_not_paid_overtime_or_holiday_premium_but_late_night_still_applies(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeManagerSupervisorWorkStyle($calendar);
        $user = User::factory()->create();
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-07', isLegalHoliday: true);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-07', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        // 法定休日に20:00〜23:00(深夜時間帯を含む)勤務。管理監督者は休日・残業割増は対象外だが、
        // 深夜割増(22-24時)と実労働時間の記録(健康管理)は引き続き必要。
        $this->editDay($user, $day, '2026-06-07', '20:00', '23:00', break: null);

        $calculation = $day->refresh()->calculation;
        $this->assertSame(180, $calculation->actual_work_minutes, '実労働時間(健康管理)はそのまま記録する');
        $this->assertSame(180, $calculation->payroll_work_minutes);
        $this->assertSame(0, $calculation->statutory_overtime_minutes, '管理監督者は残業規定の適用除外');
        $this->assertSame(0, $calculation->legal_holiday_work_minutes, '管理監督者は休日規定の適用除外のため休日割増は付けない');
        $this->assertSame(60, $calculation->legal_holiday_late_night_minutes, '深夜割増(22-23時)は管理監督者にも適用される');
    }

    public function test_manager_supervisor_long_hours_do_not_trigger_daily_statutory_overtime(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeManagerSupervisorWorkStyle($calendar);
        $user = User::factory()->create();
        $shift = $this->makeShiftAssignment($user, $workStyle, '2026-06-01');

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        // 11時間勤務(8時間を大幅に超過)。
        $this->editDay($user, $day, '2026-06-01', '09:00', '21:00');

        $calculation = $day->refresh()->calculation;
        $this->assertSame(660, $calculation->actual_work_minutes);
        $this->assertSame(660, $calculation->payroll_work_minutes);
        $this->assertSame(0, $calculation->statutory_overtime_minutes, '8時間を超えても管理監督者には残業代を計算しない');
        $this->assertSame(0, $calculation->non_statutory_overtime_minutes);
    }
}
