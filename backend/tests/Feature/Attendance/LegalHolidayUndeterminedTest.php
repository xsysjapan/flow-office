<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)。
 * docs/08-usecases-calendar-shift.md UC-C007参照。.claude/skills/attendance-calc-review 参照。
 */
class LegalHolidayUndeterminedTest extends TestCase
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

    private function makeUndeterminedWorkStyle(WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'ud-'.uniqid(), 'name' => 'シフト勤務(法定休日決めない方式)',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED,
        ]);
    }

    /**
     * 2026-06-01(月)〜06-07(日)の週の勤務予定を作る。$restDates(是働かない日)以外は
     * 稼働日にする。
     *
     * @param  list<string>  $restDates
     */
    private function makeWeekAssignments(User $user, WorkStyle $workStyle, array $restDates): void
    {
        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06', '06-07'] as $day) {
            $date = "2026-{$day}";
            $isRest = in_array($date, $restDates, true);

            EmployeeShiftAssignment::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => $isRest ? 'company_holiday' : 'weekday',
                'is_working_day' => ! $isRest,
                'is_legal_holiday' => false,
                'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function recordDay(User $user, string $workDate, string $start, string $end, bool $withLunchBreak = false): AttendanceDay
    {
        $shift = EmployeeShiftAssignment::query()
            ->where('user_id', $user->id)->whereDate('work_date', $workDate)->firstOrFail();

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $breaks = $withLunchBreak
            ? [['start' => "{$workDate}T12:00:00+09:00", 'end' => "{$workDate}T13:00:00+09:00"]]
            : [];

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => "{$workDate}T{$start}:00+09:00",
            'actual_end_at' => "{$workDate}T{$end}:00+09:00",
            'breaks' => $breaks,
            'reason' => 'テストデータ投入',
        ])->assertOk();

        return $day->refresh();
    }

    public function test_the_last_rest_day_in_the_week_is_auto_detected_as_the_legal_holiday(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();

        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-07']);

        $day = $this->recordDay($user, '2026-06-07', '10:00', '14:00');

        $this->assertSame(240, $day->calculation->legal_holiday_work_minutes, '週内で唯一の休みの日が法定休日として自動推定される');
    }

    public function test_the_later_of_two_rest_days_is_auto_detected_as_the_legal_holiday(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();

        // 木曜(06-04)と日曜(06-07)の2日が休み。より後の日曜が法定休日とみなされる。
        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-04', '2026-06-07']);

        $thursday = $this->recordDay($user, '2026-06-04', '10:00', '14:00');
        $sunday = $this->recordDay($user, '2026-06-07', '10:00', '14:00');

        $this->assertSame(0, $thursday->calculation->legal_holiday_work_minutes, '木曜は法定休日ではない(所定休日扱い)');
        $this->assertSame(240, $sunday->calculation->legal_holiday_work_minutes, 'より後の日曜が法定休日とみなされる');
    }

    public function test_a_designation_overrides_the_auto_detected_day(): void
    {
        $calendar = $this->makeCalendar();
        $admin = $this->makeAdmin();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();

        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-04', '2026-06-07']);

        // 先に日曜分を実績登録(この時点では自動推定で日曜が法定休日)。
        $sunday = $this->recordDay($user, '2026-06-07', '10:00', '14:00');
        $this->assertSame(240, $sunday->calculation->legal_holiday_work_minutes);

        // 管理者が木曜を法定休日に指定し直す。
        $this->actingAs($admin)->postJson('/api/attendance/legal-holiday-designations', [
            'user_id' => $user->id,
            'week_start_date' => '2026-06-01',
            'designated_date' => '2026-06-04',
            'reason' => '木曜を法定休日として指定する',
        ])->assertCreated();

        // 指定後は木曜分の実績を登録すると法定休日として計上され、既存の日曜分は再計算されて
        // 法定休日ではなくなる。
        $thursday = $this->recordDay($user, '2026-06-04', '10:00', '14:00');
        $this->assertSame(240, $thursday->calculation->legal_holiday_work_minutes, '指定した木曜が法定休日になる');

        $sunday->refresh();
        $this->assertSame(0, $sunday->calculation->legal_holiday_work_minutes, '指定により日曜は法定休日ではなくなる(再計算済み)');
    }

    public function test_a_week_with_no_rest_day_at_all_is_flagged_as_a_warning(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();
        $approver = User::factory()->create();

        // 7日間全て稼働日(休みなし)。
        $this->makeWeekAssignments($user, $workStyle, restDates: []);

        $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $monthId = AttendanceMonth::query()->where('user_id', $user->id)->where('year_month', '2026-06')->firstOrFail()->id;
        $toApprove = $this->actingAs($approver)->getJson('/api/attendance/months/to-approve')->assertOk();
        $warnings = collect($toApprove->json())->firstWhere('id', $monthId)['legal_holiday_warnings'];

        $violation = collect($warnings)->firstWhere('period_start', '2026-06-01');
        $this->assertNotNull($violation);
        $this->assertSame('undetermined', $violation['rule']);
        $this->assertSame(0, $violation['legal_holiday_count']);
    }

    public function test_designating_a_date_outside_the_week_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();
        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-07']);

        $this->actingAs($user)->postJson('/api/attendance/legal-holiday-designations', [
            'user_id' => $user->id,
            'week_start_date' => '2026-06-01',
            'designated_date' => '2026-06-10',
            'reason' => '週の範囲外を指定(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_designating_for_a_non_undetermined_work_style_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        $user = User::factory()->create();

        EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-06-01', 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => false, 'planned_break_minutes' => 60,
        ]);

        $this->actingAs($user)->postJson('/api/attendance/legal-holiday-designations', [
            'user_id' => $user->id,
            'week_start_date' => '2026-06-01',
            'designated_date' => '2026-06-01',
            'reason' => '決めない方式ではない勤務形態への指定(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_designating_requires_admin_role_for_other_users(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-07']);

        $this->actingAs($other)->postJson('/api/attendance/legal-holiday-designations', [
            'user_id' => $user->id,
            'week_start_date' => '2026-06-01',
            'designated_date' => '2026-06-07',
            'reason' => '他人の週を指定しようとするテスト',
        ])->assertForbidden();
    }

    public function test_a_designated_day_is_excluded_from_the_weekly_forty_hour_reference(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeUndeterminedWorkStyle($calendar);
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $this->makeWeekAssignments($user, $workStyle, restDates: ['2026-06-07']);

        foreach (['06-01', '06-02', '06-03', '06-04', '06-05', '06-06'] as $day) {
            $this->recordDay($user, "2026-{$day}", '09:00', '18:00', withLunchBreak: true);
        }
        // 日曜(自動推定で法定休日)に5時間出勤。週40時間判定には含めない。
        $this->recordDay($user, '2026-06-07', '10:00', '15:00');

        $response = $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $week = collect($response->json('weekly_overtime_reference'))->firstWhere('week_start_date', '2026-06-01');
        $this->assertSame(2880, $week['work_minutes'], '法定休日労働(日曜)は週の労働時間集計に含めない');
        $this->assertSame(300, $week['legal_holiday_work_minutes']);
    }
}
