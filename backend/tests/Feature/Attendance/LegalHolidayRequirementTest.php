<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-C005: シフト制の勤務形態について、月次まとめ承認時に法定休日要件
 * (毎週1日 / 4週4日以上の変形休日制)を満たしているか確認する。
 */
class LegalHolidayRequirementTest extends TestCase
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

    public function test_weekly_rule_flags_a_week_with_no_legal_holiday(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = WorkStyle::query()->create([
            'code' => 'shift-weekly', 'name' => 'シフト勤務(週1日休日)', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        // 2026年6月: 1日が月曜のため、週(月〜日)が暦月と揃う。
        // 第4週(6/22〜6/28)だけ法定休日を与えない。
        foreach ($this->datesInRange('2026-06-01', '2026-07-05') as $date) {
            $isWeek4 = $date >= '2026-06-22' && $date <= '2026-06-28';
            $isSunday = date('N', strtotime($date)) === '7';

            EmployeeShiftAssignment::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => $isSunday && ! $isWeek4 ? 'legal_holiday' : 'weekday',
                'is_working_day' => ! ($isSunday && ! $isWeek4),
                'is_legal_holiday' => $isSunday && ! $isWeek4,
                'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);
        }

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', '2026-06')->firstOrFail()->id;

        $toApprove = $this->actingAs($approver)->getJson('/api/attendance/months/to-approve')->assertOk();
        $warnings = collect($toApprove->json())->firstWhere('id', $monthId)['legal_holiday_warnings'];

        $this->assertCount(1, $warnings);
        $this->assertSame('weekly', $warnings[0]['rule']);
        $this->assertSame('2026-06-22', $warnings[0]['period_start']);
        $this->assertSame('2026-06-28', $warnings[0]['period_end']);
        $this->assertSame(0, $warnings[0]['legal_holiday_count']);

        // 警告があっても承認そのものはブロックしない。
        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_four_weeks_four_days_rule_flags_a_period_with_fewer_than_four_holidays(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = WorkStyle::query()->create([
            'code' => 'shift-4w4d', 'name' => 'シフト勤務(変形休日制)', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
            'four_week_period_start_date' => '2026-06-01',
        ]);
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        // 起算日2026-06-01からの最初の4週間(6/1〜6/28)に法定休日を3日しか与えない。
        foreach (['2026-06-07', '2026-06-14', '2026-06-21'] as $date) {
            EmployeeShiftAssignment::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'legal_holiday', 'is_working_day' => false, 'is_legal_holiday' => true,
                'is_company_holiday' => false, 'planned_break_minutes' => 0,
            ]);
        }
        foreach ($this->datesInRange('2026-06-01', '2026-06-30') as $date) {
            if (in_array($date, ['2026-06-07', '2026-06-14', '2026-06-21'], true)) {
                continue;
            }
            EmployeeShiftAssignment::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
                'is_company_holiday' => false, 'planned_break_minutes' => 60,
            ]);
        }

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $month = $this->actingAs($employee)->getJson('/api/attendance/months/2026-06')->assertOk()->json('month');
        $violation = collect($month['legal_holiday_warnings'])->firstWhere('period_start', '2026-06-01');

        $this->assertNotNull($violation);
        $this->assertSame('four_weeks_four_days', $violation['rule']);
        $this->assertSame('2026-06-28', $violation['period_end']);
        $this->assertSame(3, $violation['legal_holiday_count']);
        $this->assertSame(4, $violation['required_count']);
    }

    public function test_non_shift_based_work_style_is_never_checked(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-standard', 'name' => '固定時間制', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        foreach ($this->datesInRange('2026-06-01', '2026-06-30') as $date) {
            EmployeeShiftAssignment::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
                'is_company_holiday' => false, 'planned_break_minutes' => 60,
            ]);
        }

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $month = $this->actingAs($employee)->getJson('/api/attendance/months/2026-06')->assertOk()->json('month');

        $this->assertSame([], $month['legal_holiday_warnings']);
    }

    /**
     * @return list<string>
     */
    private function datesInRange(string $from, string $to): array
    {
        $dates = [];
        $cursor = strtotime($from);
        $end = strtotime($to);

        while ($cursor <= $end) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dates;
    }
}
