<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\CompanyCalendar;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveUsage;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による他社員の代休申請の取消(admin-cancel)、および代休消化明細の閲覧
 * (usages/user/{userId})。
 */
class CompensatoryLeaveAdminCancelRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(int $prescribedDailyMinutes = 480): WorkStyle
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

    private function recordAttendance(User $user, string $date, string $start, string $end, array $breaks = []): void
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

    private function confirmedGrant(User $employee, string $workDate): CompensatoryLeaveGrant
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, $workDate);
        $this->recordAttendance($employee, $workDate, '09:00', '17:00', [['start' => "{$workDate}T12:00:00+09:00", 'end' => "{$workDate}T13:00:00+09:00"]]);

        $approver = User::factory()->create();
        $month = substr($workDate, 0, 7);
        $this->actingAs($employee)->postJson("/api/attendance/months/{$month}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        return CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->firstOrFail();
    }

    private function hrUser(): User
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    public function test_admin_can_cancel_another_users_approved_request_and_restore_remaining_days(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $this->confirmedGrant($employee, '2026-08-08');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $grant->remaining_days);

        $response = $this->actingAs($hr)->postJson("/api/compensatory-leave/requests/{$requestId}/admin-cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $grant->refresh();
        $this->assertEquals(1.0, (float) $grant->remaining_days);
    }

    public function test_non_admin_gets_forbidden_on_admin_cancel_route(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $this->confirmedGrant($employee, '2026-08-08');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $this->actingAs($other)->postJson("/api/compensatory-leave/requests/{$requestId}/admin-cancel")->assertForbidden();
    }

    public function test_self_service_cancel_route_still_works_for_owner_and_rejects_non_owner(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $this->confirmedGrant($employee, '2026-08-08');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-11');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_usages_for_user_returns_rows_with_request_status_ordered_by_used_on_desc(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $this->confirmedGrant($employee, '2026-07-08');
        $this->confirmedGrant($employee, '2026-08-08');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-12');

        foreach (['2026-09-10', '2026-09-12'] as $date) {
            $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
                'target_date' => $date,
                'leave_type' => 'full',
                'approver_user_id' => $approver->id,
            ])->assertCreated()->json('id');

            $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();
        }

        $this->assertSame(2, CompensatoryLeaveUsage::query()->where('user_id', $employee->id)->count());

        $response = $this->actingAs($hr)->getJson("/api/compensatory-leave/usages/user/{$employee->id}");
        $response->assertOk();

        $usedOnDates = $response->json('*.used_on');
        $this->assertSame(['2026-09-12', '2026-09-10'], $usedOnDates);
        $this->assertSame(['approved', 'approved'], $response->json('*.request_status'));
    }
}
