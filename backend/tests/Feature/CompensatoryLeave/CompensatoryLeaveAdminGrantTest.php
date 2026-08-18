<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\CompanyCalendar;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による代休の手動付与(休日出勤の対象日を指定)・直接取消(承認不要)。
 * 既存の自動導出フロー(CompensatoryLeaveTest)とは別の経路として検証する。
 */
class CompensatoryLeaveAdminGrantTest extends TestCase
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

    public function test_admin_can_manually_grant_compensatory_leave_for_a_worked_holiday(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $hr = $this->hrStaff();
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $response = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
            'grant_reason' => '休日出勤の付け忘れを事後手動付与',
        ]);

        $response->assertCreated();
        $this->assertSame('manual', $response->json('source'));
        $this->assertSame('confirmed', $response->json('status'));
        $this->assertEquals(1.0, $response->json('granted_days'));
        $this->assertNull($response->json('attendance_day_id'));

        // 自動導出フローも同じ日付で別途draft行を作っているため、由来ごとに2行存在する。
        $this->assertSame(2, CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->count());
    }

    public function test_manual_grant_requires_an_actual_holiday_work_record(): void
    {
        $hr = $this->hrStaff();
        $employee = User::factory()->create();

        $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
        ])->assertStatus(422);
    }

    public function test_employee_cannot_manually_grant_compensatory_leave(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $other->id,
            'work_date' => '2026-08-08',
        ])->assertForbidden();
    }

    public function test_admin_can_directly_revoke_a_manually_granted_compensatory_leave(): void
    {
        SystemSetting::current()->update(['compensatory_leave_unit' => 'daily']);

        $hr = $this->hrStaff();
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $grantId = $this->actingAs($hr)->postJson('/api/compensatory-leave/grants', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-08',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($hr)->postJson("/api/compensatory-leave/grants/{$grantId}/revoke", [
            'reason' => '取消',
        ]);

        $response->assertOk();
        $this->assertSame('cancelled', CompensatoryLeaveGrant::query()->findOrFail($grantId)->status);
    }

    public function test_admin_can_directly_revoke_an_attendance_derived_grant_too(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $hr = $this->hrStaff();
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $approver = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->where('source', 'attendance')->firstOrFail();
        $this->assertSame('confirmed', $grant->status);

        // 従来の申請→承認フローとは別に、管理者が直接取消できる(sourceを問わない)。
        $response = $this->actingAs($hr)->postJson("/api/compensatory-leave/grants/{$grant->id}/revoke");

        $response->assertOk();
        $this->assertSame('cancelled', $grant->refresh()->status);
    }

    public function test_direct_revoke_is_blocked_once_the_grant_has_been_partially_used(): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => 'daily',
            'compensatory_leave_requires_approval' => false,
        ]);

        $hr = $this->hrStaff();
        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $approver = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();

        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');
        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'reason' => '代休消化',
        ])->assertCreated()->json('id');
        $this->assertSame('approved', CompensatoryLeaveRequest::query()->findOrFail($requestId)->status);

        $response = $this->actingAs($hr)->postJson("/api/compensatory-leave/grants/{$grant->id}/revoke");

        $response->assertStatus(422);
        $this->assertSame('confirmed', $grant->refresh()->status);
    }
}
