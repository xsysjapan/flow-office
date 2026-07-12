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
 * 週40時間判定(1週の法定労働時間)。日8時間超の判定(AttendanceCalculator)との二重計上を
 * 避けつつ、月次確認画面の参考情報(weekly_overtime_reference)として正しく計算されることを
 * 確認する。週次勤怠は日次勤怠の編集ビューであり、月次スナップショットには合算しない
 * (CLAUDE.md「週次勤怠は日次勤怠の編集ビュー」)。.claude/skills/attendance-calc-review 参照。
 */
class WeeklyOvertimeCalculationTest extends TestCase
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

    private function makeWorkStyle(WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    /**
     * @param  array{start: string, end: string}|null  $break
     */
    private function recordDay(
        User $user,
        WorkStyle $workStyle,
        string $workDate,
        string $actualStart,
        string $actualEnd,
        ?array $break = ['start' => '12:00', 'end' => '13:00'],
        bool $isLegalHoliday = false,
        bool $isCompanyHoliday = false,
    ): void {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => $isLegalHoliday ? 'legal_holiday' : ($isCompanyHoliday ? 'company_holiday' : 'weekday'),
            'is_working_day' => true,
            'is_legal_holiday' => $isLegalHoliday,
            'is_company_holiday' => $isCompanyHoliday,
            'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $breaks = $break === null ? [] : [[
            'start' => "{$workDate}T{$break['start']}:00+09:00",
            'end' => "{$workDate}T{$break['end']}:00+09:00",
        ]];

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => "{$workDate}T{$actualStart}:00+09:00",
            'actual_end_at' => "{$workDate}T{$actualEnd}:00+09:00",
            'breaks' => $breaks,
            'reason' => 'テストデータ投入',
        ])->assertOk();
    }

    private function submitMonth(User $user, string $yearMonth): TestResponse
    {
        $approver = User::factory()->create();

        return $this->actingAs($user)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
    }

    /**
     * @return array{week_start_date: string, week_end_date: string, actual_work_minutes: int, daily_statutory_overtime_minutes: int, weekly_statutory_overtime_minutes: int, legal_holiday_work_minutes: int}
     */
    private function weekReference(TestResponse $response, string $weekStartDate): array
    {
        return collect($response->json('weekly_overtime_reference'))->firstWhere('week_start_date', $weekStartDate);
    }

    public function test_weekly_overtime_is_captured_even_when_no_single_day_exceeds_eight_hours(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 2026-06-01(月)〜06-06(土)を7時間実働(週42時間)にする。どの日も8時間を超えない。
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '17:00');
        }

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');

        $this->assertSame(2520, $week['actual_work_minutes']);
        $this->assertSame(0, $week['daily_statutory_overtime_minutes']);
        $this->assertSame(120, $week['weekly_statutory_overtime_minutes']);
    }

    public function test_daily_and_weekly_statutory_overtime_are_not_double_counted(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 月〜木は8時間ちょうど(残業なし)、金曜のみ10時間(日8時間超2時間)。週合計は42時間。
        foreach (['06-01', '06-02', '06-03', '06-04'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '18:00');
        }
        $this->recordDay($user, $workStyle, '2026-06-05', '09:00', '20:00');

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');

        $this->assertSame(120, $week['daily_statutory_overtime_minutes'], '金曜の日8時間超(2時間)のみ');
        $this->assertSame(0, $week['weekly_statutory_overtime_minutes'], '日次で計上済みの時間を除けば週40時間ちょうどのため0');
    }

    public function test_company_holiday_work_is_included_in_the_weekly_forty_hour_threshold(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (['06-01', '06-02', '06-03', '06-04', '06-05'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '18:00');
        }
        // 土曜(法定外休日)に9時間出勤。休日出勤だが40時間判定・8時間判定の対象に含む。
        $this->recordDay($user, $workStyle, '2026-06-06', '09:00', '19:00', isCompanyHoliday: true);

        $saturday = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-06-06')->firstOrFail();
        $this->assertSame(60, $saturday->calculation->statutory_overtime_minutes, '所定休日でも日8時間超は法定時間外になる');

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');
        $this->assertSame(60, $week['daily_statutory_overtime_minutes']);
        $this->assertSame(480, $week['weekly_statutory_overtime_minutes'], '週40時間超過分(合計49時間-40時間-日次計上済み1時間)');
    }

    public function test_legal_holiday_work_is_excluded_from_the_weekly_forty_hour_aggregation(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (['06-01', '06-02', '06-03', '06-04', '06-05'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '18:00');
        }
        // 日曜(法定休日)に休憩なしで5時間出勤。
        $this->recordDay($user, $workStyle, '2026-06-07', '09:00', '14:00', break: null, isLegalHoliday: true);

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');

        $this->assertSame(2400, $week['actual_work_minutes'], '法定休日労働は週の実働集計に含めない');
        $this->assertSame(0, $week['weekly_statutory_overtime_minutes']);
        $this->assertSame(300, $week['legal_holiday_work_minutes']);
    }

    public function test_weekly_overtime_reference_is_not_folded_into_the_monthly_snapshot(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '17:00');
        }

        $response = $this->submitMonth($user, '2026-06');

        $this->assertArrayNotHasKey('weekly_statutory_overtime_minutes', $response->json('snapshot'));
    }
}
