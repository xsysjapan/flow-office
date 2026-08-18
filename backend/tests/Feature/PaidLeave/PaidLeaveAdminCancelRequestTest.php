<?php

namespace Tests\Feature\PaidLeave;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による他社員の有給申請の取消(admin-cancel)、および有給消化明細の閲覧
 * (usages/user/{userId})。
 */
class PaidLeaveAdminCancelRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = CompanyCalendar::query()->firstOrCreate(['name' => '2026年度'], ['week_starts_on' => 1]);
        if ($calendar->wasRecentlyCreated) {
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        }
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
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
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $grant = PaidLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('9.0', (string) $grant->remaining_days);

        $response = $this->actingAs($hr)->postJson("/api/paid-leave/requests/{$requestId}/admin-cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $grant->refresh();
        $this->assertSame('10.0', (string) $grant->remaining_days);
    }

    public function test_non_admin_gets_forbidden_on_admin_cancel_route(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/paid-leave/requests/{$requestId}/admin-cancel")->assertForbidden();
    }

    public function test_self_service_cancel_route_still_works_for_owner_and_rejects_non_owner(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-11');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_usages_for_user_returns_rows_with_request_status_ordered_by_used_on_desc(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $this->createWorkingDayShift($employee, '2026-08-12');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        foreach (['2026-08-10', '2026-08-12'] as $date) {
            $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
                'target_date' => $date,
                'leave_type' => 'full',
                'approver_user_id' => $approver->id,
            ])->assertCreated()->json('id');

            $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
        }

        $this->assertSame(2, PaidLeaveUsage::query()->where('user_id', $employee->id)->count());

        $response = $this->actingAs($hr)->getJson("/api/paid-leave/usages/user/{$employee->id}");
        $response->assertOk();

        $usedOnDates = $response->json('*.used_on');
        $this->assertSame(['2026-08-12', '2026-08-10'], $usedOnDates);
        $this->assertSame(['approved', 'approved'], $response->json('*.request_status'));
    }
}
