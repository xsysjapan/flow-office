<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 所定休日(法定外休日)労働の二重計上バグ修正の回帰テスト。
 *
 * 修正前は所定休日に働いた時間が`prescribed_holiday_work_minutes`と
 * `statutory_within_overtime_minutes`(法定内残業)の両方に計上されていた。修正後は
 * 所定休日の実働は全て`prescribed_holiday_work_minutes`にのみ計上し、1日8時間を超えた分
 * だけを`statutory_excess_overtime_minutes`(法定外残業)として別途計上する
 * (`statutory_within_overtime_minutes`は所定休日では常に0)。
 *
 * あわせて、日次計算後に`attendance_days.day_classification`が正しく保存されることも
 * 検証する。
 */
class PrescribedHolidayWorkTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(): WorkStyle
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

    private function makeCompanyHolidayShift(User $employee, WorkStyle $workStyle, Carbon $date): void
    {
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $date->toDateString(), 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_break_minutes' => 0,
        ]);
    }

    /**
     * 所定休日に8時間以内働いた場合、prescribed_holiday_work_minutesにのみ計上され、
     * 法定内残業・法定外残業はいずれも0になる(二重計上しない)。
     */
    public function test_prescribed_holiday_work_within_eight_hours_is_not_double_counted(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $today = Carbon::today($employee->timezone);
        $this->makeCompanyHolidayShift($employee, $workStyle, $today);
        $dateString = $today->toDateString();

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T17:00:00+09:00",
            'breaks' => [['start' => "{$dateString}T12:00:00+09:00", 'end' => "{$dateString}T13:00:00+09:00"]],
            'reason' => '所定休日出勤(7時間)',
        ])->assertCreated();

        $calculation = $response->json('calculation');

        $this->assertSame(420, $calculation['work_minutes']);
        $this->assertSame(420, $calculation['prescribed_holiday_work_minutes']);
        $this->assertSame(0, $calculation['statutory_within_overtime_minutes']);
        $this->assertSame(0, $calculation['statutory_excess_overtime_minutes']);
        $this->assertSame(0, $calculation['legal_holiday_work_minutes']);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $dateString)->firstOrFail();
        $this->assertSame('prescribed_holiday', $day->day_classification);
    }

    /**
     * 所定休日に8時間を超えて働いた場合、超過分のみ法定外残業に計上され、
     * prescribed_holiday_work_minutesは8時間分に留める(超過分を法定外残業とprescribed_holiday
     * の両方に二重計上しない。prescribed_holiday_work_minutes + statutory_excess_overtime_minutes
     * = work_minutesとなることを確認する)。
     */
    public function test_prescribed_holiday_work_over_eight_hours_counts_excess_only_once(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $today = Carbon::today($employee->timezone);
        $this->makeCompanyHolidayShift($employee, $workStyle, $today);
        $dateString = $today->toDateString();

        // 09:00〜19:00、休憩1時間 => 実働9時間(540分)
        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T19:00:00+09:00",
            'breaks' => [['start' => "{$dateString}T12:00:00+09:00", 'end' => "{$dateString}T13:00:00+09:00"]],
            'reason' => '所定休日出勤(9時間)',
        ])->assertCreated();

        $calculation = $response->json('calculation');

        $this->assertSame(540, $calculation['work_minutes']);
        // prescribed_holiday_work_minutesは8時間(480分)分のみ計上し、超過分は含めない
        // (超過分はstatutory_excess_overtime_minutes側で計上するため、両方に計上しない)。
        $this->assertSame(480, $calculation['prescribed_holiday_work_minutes']);
        // 法定内残業には計上しない(所定休日に「所定」は存在しないため)。
        $this->assertSame(0, $calculation['statutory_within_overtime_minutes']);
        // 8時間(480分)を超えた60分のみ法定外残業として計上する(二重計上しない)。
        $this->assertSame(60, $calculation['statutory_excess_overtime_minutes']);
        // prescribed_holiday_work_minutes + statutory_excess_overtime_minutes = work_minutes
        // (二重計上も欠落もないことの確認)。
        $this->assertSame($calculation['work_minutes'], $calculation['prescribed_holiday_work_minutes'] + $calculation['statutory_excess_overtime_minutes']);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $dateString)->firstOrFail();
        $this->assertSame('prescribed_holiday', $day->day_classification);
    }

    /**
     * 通常の労働日はday_classification=working_dayとして保存される。
     */
    public function test_normal_working_day_classification_is_saved(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $today = Carbon::today($employee->timezone);

        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $today->toDateString(), 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true,
            'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => $today->copy()->setTime(9, 0), 'planned_end_at' => $today->copy()->setTime(18, 0),
            'planned_break_minutes' => 60,
        ]);
        $dateString = $today->toDateString();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T18:00:00+09:00",
            'breaks' => [['start' => "{$dateString}T12:00:00+09:00", 'end' => "{$dateString}T13:00:00+09:00"]],
            'reason' => '通常勤務',
        ])->assertCreated();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $dateString)->firstOrFail();
        $this->assertSame('working_day', $day->day_classification);
    }

    /**
     * 法定休日の日はday_classification=legal_holidayとして保存される
     * (法定休日側の計算(legal_holiday_work_minutes)は今回変更していない)。
     */
    public function test_legal_holiday_classification_is_saved(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $today = Carbon::today($employee->timezone);

        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $today->toDateString(), 'work_style_id' => $workStyle->id,
            'day_type' => 'legal_holiday', 'is_working_day' => false,
            'is_legal_holiday' => true, 'is_company_holiday' => false,
            'planned_break_minutes' => 0,
        ]);
        $dateString = $today->toDateString();

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T17:00:00+09:00",
            'breaks' => [['start' => "{$dateString}T12:00:00+09:00", 'end' => "{$dateString}T13:00:00+09:00"]],
            'reason' => '法定休日出勤',
        ])->assertCreated();

        $calculation = $response->json('calculation');
        $this->assertSame(420, $calculation['legal_holiday_work_minutes']);
        $this->assertSame(0, $calculation['prescribed_holiday_work_minutes']);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $dateString)->firstOrFail();
        $this->assertSame('legal_holiday', $day->day_classification);
    }

    /**
     * 所定休日労働のうち深夜時間帯(22:00〜05:00)にかかった分は
     * late_night_prescribed_holiday_work_minutesにのみ計上し、late_night_work_minutesには
     * 二重計上しない(late_night_legal_holiday_work_minutesが法定休日側でlate_night_work_minutes
     * から除外されているのと同じ考え方)。
     */
    public function test_late_night_prescribed_holiday_work_is_not_double_counted_in_late_night_work_minutes(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $today = Carbon::today($employee->timezone);
        $this->makeCompanyHolidayShift($employee, $workStyle, $today);
        $dateString = $today->toDateString();

        // 20:00〜23:00勤務(休憩なし) => 実働3時間(180分)、うち22:00〜23:00の60分が深夜時間帯。
        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T20:00:00+09:00",
            'actual_end_at' => "{$dateString}T23:00:00+09:00",
            'breaks' => [],
            'reason' => '所定休日の深夜勤務',
        ])->assertCreated();

        $calculation = $response->json('calculation');

        $this->assertSame(180, $calculation['work_minutes']);
        $this->assertSame(180, $calculation['prescribed_holiday_work_minutes']);
        $this->assertSame(60, $calculation['late_night_prescribed_holiday_work_minutes']);
        // 所定休日の深夜労働はlate_night_work_minutesには計上しない(二重計上防止)。
        $this->assertSame(0, $calculation['late_night_work_minutes']);
    }
}
