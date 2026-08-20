<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Services\WeeklyOvertimeCalculator;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 週40時間判定(1週の法定労働時間)。日8時間超の判定(AttendanceCalculator)との二重計上を
 * 避けつつ、月次確認画面の週ごとの参考情報(weekly_overtime_reference)として正しく計算される
 * ことを確認する。週次勤怠は日次勤怠の編集ビューであり、この週ごとの内訳自体は月次
 * スナップショットには合算しない(CLAUDE.md「週次勤怠は日次勤怠の編集ビュー」)が、月内の
 * 全週を合算した確定値は月60時間超と同様にmonthly_calculation_totals/snapshotの
 * weekly_statutory_excess_overtime_minutesに含まれる。.claude/skills/attendance-calc-review 参照。
 */
class WeeklyOvertimeCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCalendar(): CompanyCalendar
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return $calendar;
    }

    private function makeWorkStyle(CompanyCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
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
        $shift = EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => $isLegalHoliday ? 'legal_holiday' : ($isCompanyHoliday ? 'company_holiday' : 'weekday'),
            'is_working_day' => true,
            'is_legal_holiday' => $isLegalHoliday,
            'is_company_holiday' => $isCompanyHoliday,
            'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'calendar_entry_id' => $shift->id,
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
        foreach (app(WeeklyOvertimeCalculator::class)->calculateForMonth($user->id, $yearMonth) as $week) {
            $minutes = $week['unallocated_weekly_statutory_excess_overtime_minutes'];
            if ($minutes <= 0) {
                continue;
            }
            $day = AttendanceDay::query()->where('user_id', $user->id)
                ->whereBetween('work_date', [$week['week_start_date'], $week['week_end_date']])
                ->whereHas('calculation', fn ($query) => $query->where('non_prescribed_statutory_within_work_minutes', '>=', $minutes))
                ->latest('work_date')->first();
            $category = 'non_prescribed';
            if ($day === null) {
                $day = AttendanceDay::query()->where('user_id', $user->id)
                    ->whereBetween('work_date', [$week['week_start_date'], $week['week_end_date']])
                    ->whereHas('calculation', fn ($query) => $query->where('prescribed_statutory_within_work_minutes', '>=', $minutes))
                    ->latest('work_date')->firstOrFail();
                $category = 'prescribed';
            }
            $this->actingAs($user)->putJson("/api/attendance/weeks/{$week['week_start_date']}/overtime-allocations", [
                'allocations' => [[
                    'attendance_day_id' => $day->id,
                    'prescribed_minutes' => $category === 'prescribed' ? $minutes : 0,
                    'non_prescribed_minutes' => $category === 'non_prescribed' ? $minutes : 0,
                    'late_night_prescribed_minutes' => 0,
                    'late_night_non_prescribed_minutes' => 0,
                ]],
            ])->assertOk();
        }
        $approver = User::factory()->create();

        return $this->actingAs($user)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
    }

    /**
     * @return array{week_start_date: string, week_end_date: string, work_minutes: int, daily_statutory_excess_overtime_minutes: int, weekly_statutory_excess_overtime_minutes: int, legal_holiday_work_minutes: int}
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

        // 2026-06-01(月)〜06-06(土)を7時間労働(週42時間)にする。どの日も8時間を超えない。
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '17:00');
        }

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');

        $this->assertSame(2520, $week['work_minutes']);
        $this->assertSame(0, $week['daily_statutory_excess_overtime_minutes']);
        $this->assertSame(120, $week['weekly_statutory_excess_overtime_minutes']);
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

        $this->assertSame(120, $week['daily_statutory_excess_overtime_minutes'], '金曜の日8時間超(2時間)のみ');
        $this->assertSame(0, $week['weekly_statutory_excess_overtime_minutes'], '日次で計上済みの時間を除けば週40時間ちょうどのため0');
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
        $this->assertSame(540, $saturday->calculation->statutory_excess_overtime_minutes, '日8時間超1時間と週40時間超8時間を法定時間外にする');
        $this->assertSame(0, $saturday->calculation->non_prescribed_statutory_within_work_minutes);
        $this->assertSame(540, $saturday->calculation->non_prescribed_statutory_excess_work_minutes);

        $week = $this->weekReference($this->submitMonth($user, '2026-06'), '2026-06-01');
        $this->assertSame(60, $week['daily_statutory_excess_overtime_minutes']);
        $this->assertSame(480, $week['weekly_statutory_excess_overtime_minutes'], '週40時間超過分(合計49時間-40時間-日次計上済み1時間)');
        $this->assertSame(0, $week['unallocated_weekly_statutory_excess_overtime_minutes']);
    }

    public function test_non_prescribed_work_at_exactly_forty_hours_remains_statutory_within(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();
        foreach (['06-01', '06-02', '06-03', '06-04'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '18:00');
        }
        $this->recordDay($user, $workStyle, '2026-06-06', '09:00', '18:00', isCompanyHoliday: true);

        $saturday = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-06-06')->firstOrFail();
        $this->assertSame(480, $saturday->calculation->non_prescribed_statutory_within_work_minutes);
        $this->assertSame(0, $saturday->calculation->non_prescribed_statutory_excess_work_minutes);
        $this->assertFalse(AttendanceWeeklyOvertimeAllocation::query()->where('attendance_day_id', $saturday->id)->exists());
    }

    public function test_company_holiday_after_daily_overtime_is_automatically_allocated_to_weekly_excess(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();
        foreach ([
            '07-13' => '18:30', '07-14' => '18:30', '07-15' => '18:30',
            '07-16' => '19:30', '07-17' => '19:00',
        ] as $day => $end) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', $end);
        }
        $this->recordDay($user, $workStyle, '2026-07-18', '09:00', '18:00', isCompanyHoliday: true);

        $saturday = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-07-18')->firstOrFail();
        $this->assertSame(0, $saturday->calculation->non_prescribed_statutory_within_work_minutes);
        $this->assertSame(480, $saturday->calculation->non_prescribed_statutory_excess_work_minutes);
        $this->assertSame(480, AttendanceWeeklyOvertimeAllocation::query()->where('attendance_day_id', $saturday->id)->value('non_prescribed_minutes'));

        $week = app(WeeklyOvertimeCalculator::class)->calculateWeek($user->id, '2026-07-13', '2026-07-19');
        $this->assertSame(480, $week['weekly_statutory_excess_overtime_minutes']);
        $this->assertSame(0, $week['unallocated_weekly_statutory_excess_overtime_minutes']);
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

        $this->assertSame(2400, $week['work_minutes'], '法定休日労働は週の労働時間集計に含めない');
        $this->assertSame(0, $week['weekly_statutory_excess_overtime_minutes']);
        $this->assertSame(300, $week['legal_holiday_work_minutes']);
    }

    /**
     * UC-A008: 週ごとの内訳(weekly_overtime_reference)自体はsnapshotに合算しないが、月内の
     * 全週を合算した週40時間超残業の総量は月60時間超と同様に確定値としてsnapshotへ含める。
     */
    public function test_weekly_overtime_is_frozen_into_the_monthly_snapshot_on_submission(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 06-01(月)〜06-06(土)を7時間労働(週42時間、週40時間超が120分)にする。
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '17:00');
        }

        $response = $this->submitMonth($user, '2026-06');

        $this->assertSame(120, $response->json('snapshot.weekly_statutory_excess_overtime_minutes'));
    }

    public function test_month_submission_is_blocked_when_cross_month_weekly_overtime_is_unallocated(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 6月末と7月初を合わせた月〜土が42時間。7月分だけを見ても判定を逃れない。
        foreach (['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $date) {
            $this->recordDay($user, $workStyle, $date, '09:00', '17:00');
        }

        $approver = User::factory()->create();
        $this->actingAs($user)->postJson('/api/attendance/months/2026-07/submit', [
            'approver_user_id' => $approver->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', '週40時間超の法定外労働時間が未振分です（2026-06-29〜2026-07-05: 120分）。対象の勤務日へ振り分けてください。');
    }

    public function test_allocation_moves_selected_category_and_can_be_replaced(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, $workStyle, "2026-{$day}", '09:00', '17:00');
        }
        $target = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', '2026-06-06')->firstOrFail();

        $payload = fn (int $minutes) => ['allocations' => [[
            'attendance_day_id' => $target->id,
            'prescribed_minutes' => $minutes,
            'non_prescribed_minutes' => 0,
            'late_night_prescribed_minutes' => 0,
            'late_night_non_prescribed_minutes' => 0,
        ]]];
        $this->actingAs($user)->putJson('/api/attendance/weeks/2026-06-01/overtime-allocations', $payload(120))->assertOk();
        $target->calculation->refresh();
        $this->assertSame(300, $target->calculation->prescribed_statutory_within_work_minutes);
        $this->assertSame(120, $target->calculation->prescribed_statutory_excess_work_minutes);
        $this->assertSame(120, $target->calculation->statutory_excess_overtime_minutes, 'Excel・汎用/freee CSV用の互換列にも反映する');

        // 既存配賦を考慮して60分へ戻せる（現在値だけを容量にすると更新不能になる回帰テスト）。
        $this->actingAs($user)->putJson('/api/attendance/weeks/2026-06-01/overtime-allocations', $payload(60))->assertOk();
        $target->calculation->refresh();
        $this->assertSame(360, $target->calculation->prescribed_statutory_within_work_minutes);
        $this->assertSame(60, $target->calculation->prescribed_statutory_excess_work_minutes);
        $this->assertSame(60, $target->calculation->statutory_excess_overtime_minutes);

        $week = app(WeeklyOvertimeCalculator::class)->calculateWeek($user->id, '2026-06-01', '2026-06-07');
        $this->assertSame(120, $week['weekly_statutory_excess_overtime_minutes'], '互換列更新後も週超過の元値は変えない');
        $this->assertSame(60, $week['unallocated_weekly_statutory_excess_overtime_minutes']);
    }
}
