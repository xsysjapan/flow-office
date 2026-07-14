<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 月60時間超残業(労基法37条)判定。日次勤怠取得(AttendanceDayResource.monthly_overtime)の
 * たびに月初から都度合算する参考情報として提供され、Projectionとして永続化されないことを
 * 確認する。.claude/skills/attendance-calc-review 参照。
 */
class MonthlyOvertimeCalculationTest extends TestCase
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

    private function recordDay(
        User $user,
        WorkStyle $workStyle,
        string $workDate,
        string $actualStart,
        string $actualEnd,
        bool $isLegalHoliday = false,
    ): AttendanceDay {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => $isLegalHoliday ? 'legal_holiday' : 'weekday',
            'is_working_day' => true, 'is_legal_holiday' => $isLegalHoliday, 'is_company_holiday' => false,
            'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => "{$workDate}T{$actualStart}:00+09:00",
            'actual_end_at' => "{$workDate}T{$actualEnd}:00+09:00",
            'breaks' => [[
                'start' => "{$workDate}T12:00:00+09:00",
                'end' => "{$workDate}T13:00:00+09:00",
            ]],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        return $day->refresh();
    }

    public function test_statutory_overtime_over_60_hours_is_split_within_the_current_day(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 2026-06-01〜06-11の11日間、1日5時間(300分)の法定外残業を積み上げる(累計3,300分)。
        foreach (range(1, 11) as $i) {
            $this->recordDay($user, $workStyle, sprintf('2026-06-%02d', $i), '09:00', '23:00');
        }

        // 06-12は5時間40分(340分)の法定外残業。累計3,300+340=3,640分のうち、
        // 60時間(3,600分)を超える40分だけがその日の「月60時間超残業」になる。
        $day = $this->recordDay($user, $workStyle, '2026-06-12', '09:00', '23:40');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(340, $response->json('calculation.statutory_overtime_minutes'));
        $this->assertSame(3640, $response->json('monthly_overtime.cumulative_statutory_overtime_minutes'));
        $this->assertSame(300, $response->json('monthly_overtime.statutory_overtime_within_60h_minutes'));
        $this->assertSame(40, $response->json('monthly_overtime.statutory_overtime_over_60h_minutes'));
    }

    public function test_days_before_reaching_the_sixty_hour_threshold_have_no_excess(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-06-01', '09:00', '23:00');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(300, $response->json('monthly_overtime.statutory_overtime_within_60h_minutes'));
        $this->assertSame(0, $response->json('monthly_overtime.statutory_overtime_over_60h_minutes'));
    }

    public function test_legal_holiday_work_is_excluded_from_the_sixty_hour_aggregation(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-06-07', '09:00', '23:00', isLegalHoliday: true);

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(0, $response->json('calculation.statutory_overtime_minutes'), '法定休日労働は法定外残業に含まれない');
        $this->assertSame(0, $response->json('monthly_overtime.statutory_overtime_over_60h_minutes'));
    }
}
