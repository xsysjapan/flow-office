<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 代休履歴(paid-leave/special-leaveと同様、EventStoreを正として付与・申請・承認の
 * イベントを時系列で確認できることを検証する)。
 */
class CompensatoryLeaveHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function makeHolidayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_break_minutes' => 0,
        ]);
    }

    private function makeWorkingDayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true,
            'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    private function recordAttendance(User $user, string $date, string $start, string $end): void
    {
        $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id,
            'work_date' => $date,
            'actual_start_at' => "{$date}T{$start}:00+09:00",
            'actual_end_at' => "{$date}T{$end}:00+09:00",
            'breaks' => [],
            'reason' => 'テスト勤務',
        ])->assertCreated();
    }

    private function hrStaff(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    public function test_an_employee_can_see_their_own_history_in_chronological_order(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $hr = $this->hrStaff();
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
            'grant_reason' => '休日出勤の代休',
        ])->assertCreated();

        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');
        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $response = $this->actingAs($employee)->getJson('/api/compensatory-leave/history/mine');
        $response->assertOk();

        $eventTypes = collect($response->json())->pluck('event_type')->all();
        $this->assertContains('compensatory_leave.manually_granted', $eventTypes);
        $this->assertContains('compensatory_leave.requested', $eventTypes);
        $this->assertContains('compensatory_leave.request_approved', $eventTypes);

        // 時系列(新しい順)であることを確認する: 承認が付与より後のインデックス=先頭側に来る。
        $grantedIndex = array_search('compensatory_leave.manually_granted', $eventTypes, true);
        $approvedIndex = array_search('compensatory_leave.request_approved', $eventTypes, true);
        $this->assertLessThan($grantedIndex, $approvedIndex);
    }

    public function test_an_employee_cannot_see_another_employees_history(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->getJson("/api/compensatory-leave/history/user/{$other->id}")->assertForbidden();
    }

    public function test_admin_and_hr_staff_can_see_any_employees_history(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $employee = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $hr = $this->hrStaff();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
        ])->assertCreated();

        $response = $this->actingAs($admin)->getJson("/api/compensatory-leave/history/user/{$employee->id}");
        $response->assertOk();
        $this->assertContains('compensatory_leave.manually_granted', collect($response->json())->pluck('event_type')->all());

        $this->actingAs($hr)->getJson("/api/compensatory-leave/history/user/{$employee->id}")->assertOk();
    }

    public function test_history_only_includes_events_for_the_target_user(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $employee = User::factory()->create();
        $other = User::factory()->create();
        $hr = $this->hrStaff();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');
        $this->makeHolidayShift($other, $workStyle, '2026-08-09');
        $this->recordAttendance($other, '2026-08-09', '09:00', '17:00');

        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
        ])->assertCreated();
        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $other->id,
            'work_date' => '2026-08-09',
        ])->assertCreated();

        $response = $this->actingAs($employee)->getJson('/api/compensatory-leave/history/mine');
        $response->assertOk();
        $userIds = collect($response->json())->pluck('payload.user_id')->unique()->all();
        $this->assertSame([$employee->id], $userIds);
    }
}
