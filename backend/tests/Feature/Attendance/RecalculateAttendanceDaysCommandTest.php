<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\RecalculateAttendanceDailyCalculation;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * attendance:recalculate-days(日次計算の一括再計算、AdminExecutable)のテスト。
 *
 * 保存済みの日次計算を、計算ロジックの結果と比べて変わる値を一覧する。既定は試し実行(記録しない)、
 * --apply で変わる日だけ attendance_day.calculated を記録する。手動調整済みの日は対象外として一覧に出す。
 */
class RecalculateAttendanceDaysCommandTest extends TestCase
{
    use RefreshDatabase;

    private ?WorkStyle $style = null;

    private function workingDay(User $user, string $date): void
    {
        if ($this->style === null) {
            $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
            $this->style = WorkStyle::query()->create([
                'code' => 'standard-recalc', 'name' => '通常勤務', 'work_time_system' => 'fixed',
                'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
                'default_start_time' => '09:00', 'default_end_time' => '18:00',
                'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
            ]);
        }

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $this->style->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    /** 全休の有給を承認まで通し、休暇だけの勤怠日(計算済み)を作る。勤怠日のIDを返す。 */
    private function fullDayLeaveFor(User $employee, User $approver, string $date): string
    {
        $this->workingDay($employee, $date);
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        return AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $date)->firstOrFail()->id;
    }

    /** 有給の日数だけを0にした古い日次計算を記録する(計算ロジックの結果とは違う値を保存済みにする)。 */
    private function recordStaleCalculation(string $attendanceDayId): void
    {
        $day = AttendanceDay::query()->with(['breaks', 'leaveSegments', 'calendarEntry.workStyle'])->findOrFail($attendanceDayId);
        $calculation = app(AttendanceCalculator::class)->calculate($day);
        $calculation['paid_leave_days'] = 0.0;

        AttendanceDayAggregate::retrieve($attendanceDayId)->calculate($calculation)->persist();
    }

    private function calculatedEventCount(): int
    {
        return DB::table('stored_events')->where('event_class', 'attendance_day.calculated')->count();
    }

    private function storedPaidLeaveDays(string $attendanceDayId): string
    {
        return (string) AttendanceDailyCalculation::query()->where('attendance_day_id', $attendanceDayId)->value('paid_leave_days');
    }

    public function test_the_range_is_required_and_must_be_a_real_date(): void
    {
        $this->artisan('attendance:recalculate-days')
            ->expectsOutputToContain('--from と --to は必須です')
            ->assertExitCode(1);

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-02-30', '--to' => '2026-03-01'])
            ->expectsOutputToContain('実在する日付')
            ->assertExitCode(1);

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-31', '--to' => '2026-08-01'])
            ->expectsOutputToContain('--from は --to 以前の日付')
            ->assertExitCode(1);
    }

    public function test_a_dry_run_lists_the_changed_values_and_records_nothing(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $dayId = $this->fullDayLeaveFor($employee, $approver, '2026-08-10');
        $this->recordStaleCalculation($dayId);
        $events = $this->calculatedEventCount();

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-01', '--to' => '2026-08-31'])
            ->expectsOutputToContain('paid_leave_days: 0.00 → 1')
            ->expectsOutputToContain('試し実行のため記録していません')
            ->assertSuccessful();

        $this->assertSame($events, $this->calculatedEventCount());
        $this->assertSame('0.00', $this->storedPaidLeaveDays($dayId));
    }

    public function test_apply_records_the_changed_days_and_a_second_run_finds_no_change(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $dayId = $this->fullDayLeaveFor($employee, $approver, '2026-08-10');
        $this->recordStaleCalculation($dayId);

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--apply' => true])
            ->expectsOutputToContain('1 件の日次計算を記録しました')
            ->assertSuccessful();
        $this->assertSame('1.00', $this->storedPaidLeaveDays($dayId));

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--apply' => true])
            ->expectsOutputToContain('変更あり 0 件')
            ->doesntExpectOutputToContain('[変更]')
            ->assertSuccessful();
    }

    public function test_manually_adjusted_days_are_excluded_and_listed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $dayId = $this->fullDayLeaveFor($employee, $approver, '2026-08-10');
        $this->recordStaleCalculation($dayId);
        AttendanceDayAggregate::retrieve($dayId)->adjustCalculation(
            prescribedWorkMinutes: 0,
            statutoryWithinOvertimeMinutes: 0,
            statutoryExcessOvertimeMinutes: 0,
            legalHolidayWorkMinutes: 0,
            prescribedHolidayWorkMinutes: 0,
            payrollWorkMinutes: 0,
            lateNightPrescribedWorkMinutes: 0,
            lateNightStatutoryWithinOvertimeMinutes: 0,
            lateNightStatutoryExcessOvertimeMinutes: 0,
            lateNightLegalHolidayWorkMinutes: 0,
            lateNightPrescribedHolidayWorkMinutes: 0,
            reason: '社労士確認後の手動調整',
            adjustedByUserId: $approver->id,
        )->persist();

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--apply' => true])
            ->expectsOutputToContain('[除外]')
            ->expectsOutputToContain('手動調整済みで除外 1 件')
            ->doesntExpectOutputToContain('[変更]')
            ->assertSuccessful();

        $this->assertSame('0.00', $this->storedPaidLeaveDays($dayId));
    }

    public function test_the_user_option_limits_the_target_to_the_given_users(): void
    {
        $approver = User::factory()->create();
        $target = User::factory()->create();
        $other = User::factory()->create();
        $this->recordStaleCalculation($this->fullDayLeaveFor($target, $approver, '2026-08-10'));
        $this->recordStaleCalculation($this->fullDayLeaveFor($other, $approver, '2026-08-11'));

        $this->artisan('attendance:recalculate-days', ['--from' => '2026-08-01', '--to' => '2026-08-31', '--user' => [$target->id]])
            ->expectsOutputToContain('対象 1 件')
            ->expectsOutputToContain($target->id)
            ->doesntExpectOutputToContain($other->id)
            ->assertSuccessful();
    }

    public function test_the_recalculation_command_rejects_a_manually_adjusted_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $dayId = $this->fullDayLeaveFor($employee, $approver, '2026-08-10');
        AttendanceDayAggregate::retrieve($dayId)->adjustCalculation(
            prescribedWorkMinutes: 0,
            statutoryWithinOvertimeMinutes: 0,
            statutoryExcessOvertimeMinutes: 0,
            legalHolidayWorkMinutes: 0,
            prescribedHolidayWorkMinutes: 0,
            payrollWorkMinutes: 0,
            lateNightPrescribedWorkMinutes: 0,
            lateNightStatutoryWithinOvertimeMinutes: 0,
            lateNightStatutoryExcessOvertimeMinutes: 0,
            lateNightLegalHolidayWorkMinutes: 0,
            lateNightPrescribedHolidayWorkMinutes: 0,
            reason: '手動調整',
            adjustedByUserId: $approver->id,
        )->persist();
        $events = $this->calculatedEventCount();

        $this->expectException(DomainRuleException::class);
        try {
            app(CommandBus::class)->dispatch(new RecalculateAttendanceDailyCalculation($dayId));
        } finally {
            $this->assertSame($events, $this->calculatedEventCount());
        }
    }
}
