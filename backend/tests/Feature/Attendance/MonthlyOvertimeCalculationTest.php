<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
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
        bool $isCompanyHoliday = false,
    ): AttendanceDay {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => $isLegalHoliday ? 'legal_holiday' : ($isCompanyHoliday ? 'company_holiday' : 'weekday'),
            'is_working_day' => ! $isCompanyHoliday, 'is_legal_holiday' => $isLegalHoliday, 'is_company_holiday' => $isCompanyHoliday,
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

        $this->assertSame(340, $response->json('calculation.statutory_excess_overtime_minutes'));
        $this->assertSame(3640, $response->json('monthly_overtime.cumulative_statutory_excess_overtime_minutes'));
        $this->assertSame(300, $response->json('monthly_overtime.statutory_excess_overtime_within_60h_minutes'));
        $this->assertSame(40, $response->json('monthly_overtime.statutory_excess_overtime_over_60h_minutes'));
    }

    public function test_days_before_reaching_the_sixty_hour_threshold_have_no_excess(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-06-01', '09:00', '23:00');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(300, $response->json('monthly_overtime.statutory_excess_overtime_within_60h_minutes'));
        $this->assertSame(0, $response->json('monthly_overtime.statutory_excess_overtime_over_60h_minutes'));
    }

    public function test_legal_holiday_work_is_excluded_from_the_sixty_hour_aggregation(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-06-07', '09:00', '23:00', isLegalHoliday: true);

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(0, $response->json('calculation.statutory_excess_overtime_minutes'), '法定休日労働は法定外残業に含まれない');
        $this->assertSame(0, $response->json('monthly_overtime.statutory_excess_overtime_over_60h_minutes'));
    }

    /**
     * UC-A007: 月次確認画面は提出前でも当月の集計(9区分の合計)を都度計算して表示する。
     */
    public function test_month_endpoint_returns_the_monthly_calculation_totals_before_submission(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        $this->recordDay($user, $workStyle, '2026-06-01', '09:00', '18:00');
        $this->recordDay($user, $workStyle, '2026-06-02', '09:00', '23:00');

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-06')->assertOk();
        $totals = $response->json('monthly_calculation_totals');

        $this->assertSame(780 + 480, $totals['work_minutes']);
        $this->assertSame(300, $totals['statutory_excess_overtime_minutes']);
        $this->assertSame(300, $totals['statutory_excess_overtime_within_60h_minutes']);
        $this->assertSame(0, $totals['statutory_excess_overtime_over_60h_minutes']);
        $this->assertSame(60, $totals['late_night_work_minutes']);
        $this->assertSame(60, $totals['late_night_statutory_excess_overtime_minutes']);
    }

    /**
     * 所定休日労働のうち深夜時間帯にかかった分(late_night_prescribed_holiday_work_minutes)は
     * 月次集計にも合算される。
     */
    public function test_late_night_prescribed_holiday_work_minutes_is_aggregated_into_the_monthly_totals(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 20:00〜23:00の所定休日勤務(休憩なし) => 深夜(22:00〜23:00)60分が
        // late_night_prescribed_holiday_work_minutesに計上される。
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_break_minutes' => 0,
        ]);
        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);
        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T20:00:00+09:00',
            'actual_end_at' => '2026-06-01T23:00:00+09:00',
            'breaks' => [],
            'reason' => '所定休日の深夜勤務',
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-06')->assertOk();
        $totals = $response->json('monthly_calculation_totals');

        $this->assertSame(60, $totals['late_night_prescribed_holiday_work_minutes']);
        $this->assertSame(0, $totals['late_night_work_minutes'], '所定休日の深夜労働はlate_night_work_minutesに二重計上しない');
    }

    /**
     * UC-A008: 月次提出時のスナップショットにも、法定内残業/法定外残業/月60時間超残業/深夜時間等の
     * 合計(60時間以内/超の按分含む)を確定値として保存する。
     */
    public function test_submitting_the_month_freezes_the_monthly_calculation_totals_into_the_snapshot(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (range(1, 12) as $i) {
            $this->recordDay($user, $workStyle, sprintf('2026-06-%02d', $i), '09:00', '23:00');
        }

        $approver = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $snapshot = $response->json('snapshot');

        $this->assertSame(3600, $snapshot['statutory_excess_overtime_minutes'], '1日300分×12日');
        $this->assertSame(3600, $snapshot['statutory_excess_overtime_within_60h_minutes']);
        $this->assertSame(0, $snapshot['statutory_excess_overtime_over_60h_minutes']);
        $this->assertSame(720, $snapshot['late_night_statutory_excess_overtime_minutes'], '1日60分×12日');
    }

    /**
     * UC-A008: 週40時間(労基法32条)超残業も月60時間超と同様に、月次確認画面では都度計算した
     * 進捗の目安として表示され、月次提出時にはその月内の全週を合算した値が確定値として
     * スナップショットに保存される(WeeklyOvertimeCalculator参照)。
     */
    public function test_weekly_overtime_over_40_hours_is_frozen_into_the_snapshot_at_submission(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 2026-06-01(月)〜06-06(土)の6日間、1日9時間(540分)勤務(日8時間超の60分/日は既に
        // その日の法定外残業として計上済み)。週の所定内労働(480分/日)の合計は2,880分となり、
        // 週40時間(2,400分)を480分超える。
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06'] as $date) {
            $this->recordDay($user, $workStyle, $date, '09:00', '19:00');
        }

        $monthResponse = $this->actingAs($user)->getJson('/api/attendance/months/2026-06')->assertOk();
        $this->assertSame(480, $monthResponse->json('monthly_calculation_totals.weekly_statutory_excess_overtime_minutes'));

        $approver = User::factory()->create();
        $submitResponse = $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $this->assertSame(480, $submitResponse->json('snapshot.weekly_statutory_excess_overtime_minutes'));
    }

    /**
     * UC-A007: 月次確認画面は特別休暇の内訳をspecial_leave_type_idごとに返す
     * (.claude/skills/attendance-calc-review 参照。docs/07-usecases-attendance.md「不就労時間の処理区分」)。
     */
    public function test_month_endpoint_returns_the_special_leave_breakdown_by_type(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $birthdayType = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $refreshType = SpecialLeaveType::query()->create(['name' => 'リフレッシュ休暇', 'is_active' => true]);

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $birthdayType->id,
            'granted_on' => '2026-04-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);
        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $refreshType->id,
            'granted_on' => '2026-04-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        // 全休(誕生日休暇) 2026-06-03。
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-06-03', 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => '2026-06-03 09:00:00', 'planned_end_at' => '2026-06-03 18:00:00',
            'planned_break_minutes' => 60,
        ]);
        $birthdayRequestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $birthdayType->id,
            'target_date' => '2026-06-03',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '誕生日のため',
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$birthdayRequestId}/approve")->assertOk();

        // 時間単位(リフレッシュ休暇) 2026-06-04, 3時間。
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-06-04', 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => '2026-06-04 09:00:00', 'planned_end_at' => '2026-06-04 18:00:00',
            'planned_break_minutes' => 60,
        ]);
        $refreshRequestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $refreshType->id,
            'target_date' => '2026-06-04',
            'leave_type' => 'hourly',
            'hours' => 3,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$refreshRequestId}/approve")->assertOk();

        $response = $this->actingAs($employee)->getJson('/api/attendance/months/2026-06')->assertOk();
        $breakdown = collect($response->json('special_leave_breakdown'))->keyBy('special_leave_type_name');

        $this->assertEquals(1.0, $breakdown['誕生日休暇']['days']);
        $this->assertEquals(0, $breakdown['誕生日休暇']['minutes']);
        $this->assertEquals(0.0, $breakdown['リフレッシュ休暇']['days']);
        $this->assertEquals(180, $breakdown['リフレッシュ休暇']['minutes']);

        $totals = $response->json('monthly_calculation_totals');
        $this->assertEquals(1.0, $totals['special_leave_days']);
        $this->assertEquals(180, $totals['special_leave_minutes']);
    }
}
