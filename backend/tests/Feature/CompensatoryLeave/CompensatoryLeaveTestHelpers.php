<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Models\CompanyCalendar;
use App\Models\CompensatoryLeaveGrant;
use App\Models\EmployeeCalendarEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;

/**
 * 代休のシナリオテスト・移行テストで共通に使う準備(勤務予定・休日出勤の勤怠・月次提出)。
 * 業務の操作はAPI(HTTP)経由で行い、状態はDB(投影)とイベントで確認する。
 */
trait CompensatoryLeaveTestHelpers
{
    private function makeCompensatoryWorkStyle(int $prescribedDailyMinutes = 480): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function makeCompensatoryHolidayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_break_minutes' => 0,
        ]);
    }

    private function makeCompensatoryWorkingDayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeCalendarEntry::query()->updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $date],
            ['work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true,
            'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    private function recordCompensatoryAttendance(User $user, string $date, string $start, string $end, array $breaks = []): void
    {
        $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => $date,
            'actual_start_at' => "{$date}T{$start}:00+09:00",
            'actual_end_at' => "{$date}T{$end}:00+09:00",
            'breaks' => $breaks,
            'reason' => 'テスト勤務',
        ])->assertCreated();
    }

    private function enableCompensatoryLeave(string $unit = 'daily', ?int $validDays = null): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => $unit,
            'compensatory_leave_valid_days' => $validDays,
        ]);
    }

    /**
     * 休日出勤(2026-08の指定日)を記録し、その月を提出して確定済みの付与を返す。
     *
     * @param  string[]  $workDates  休日出勤日(すべて2026-08)
     */
    private function holidayWorkGrantedAndConfirmed(User $employee, array $workDates = ['2026-08-08']): CompensatoryLeaveGrant
    {
        $this->enableCompensatoryLeave();

        $workStyle = $this->makeCompensatoryWorkStyle();

        foreach ($workDates as $workDate) {
            $this->makeCompensatoryHolidayShift($employee, $workStyle, $workDate);
            $this->recordCompensatoryAttendance($employee, $workDate, '09:00', '17:00', [
                ['start' => "{$workDate}T12:00:00+09:00", 'end' => "{$workDate}T13:00:00+09:00"],
            ]);
        }

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => User::factory()->create()->id,
        ])->assertSuccessful();

        return CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->orderBy('work_date')->firstOrFail();
    }

    /** 代休を申請して申請IDを返す(承認者は指定の利用者)。 */
    private function requestCompensatoryLeave(User $employee, User $approver, string $targetDate, string $leaveType = 'full', array $extra = []): string
    {
        $workStyle = WorkStyle::query()->firstOrFail();
        $this->makeCompensatoryWorkingDayShift($employee, $workStyle, $targetDate);

        return $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', array_merge([
            'target_date' => $targetDate,
            'leave_type' => $leaveType,
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ], $extra))->assertCreated()->json('id');
    }

    /** 代休の残数(投影)を集約の読み取りで確認するための利用者の口座。 */
    private function compensatoryAccountOf(User $user): CompensatoryLeaveAccountAggregate
    {
        return CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($user->id))
            ->forUser($user->id);
    }
}
