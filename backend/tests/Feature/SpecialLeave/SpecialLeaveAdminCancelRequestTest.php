<?php

namespace Tests\Feature\SpecialLeave;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\Role;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による他社員の特別休暇申請の取消(admin-cancel)、および特別休暇消化明細の閲覧
 * (usages/user/{userId})。
 */
class SpecialLeaveAdminCancelRequestTest extends TestCase
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

    private function createType(): SpecialLeaveType
    {
        return SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
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
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();

        $grant = SpecialLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertEquals(2.0, (float) $grant->remaining_days);

        $response = $this->actingAs($hr)->postJson("/api/special-leave/requests/{$requestId}/admin-cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $grant->refresh();
        $this->assertEquals(3.0, (float) $grant->remaining_days);
    }

    public function test_non_admin_gets_forbidden_on_admin_cancel_route(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/special-leave/requests/{$requestId}/admin-cancel")->assertForbidden();
    }

    public function test_self_service_cancel_route_still_works_for_owner_and_rejects_non_owner(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-11');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($other)->postJson("/api/special-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_usages_for_user_returns_rows_with_request_status_ordered_by_used_on_desc(): void
    {
        $hr = $this->hrUser();
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $this->createWorkingDayShift($employee, '2026-08-12');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        foreach (['2026-08-10', '2026-08-12'] as $date) {
            $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
                'special_leave_type_id' => $type->id,
                'target_date' => $date,
                'leave_type' => 'full',
                'approver_user_id' => $approver->id,
            ])->assertCreated()->json('id');

            $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();
        }

        $this->assertSame(2, SpecialLeaveUsage::query()->where('user_id', $employee->id)->count());

        $response = $this->actingAs($hr)->getJson("/api/special-leave/usages/user/{$employee->id}");
        $response->assertOk();

        $usedOnDates = $response->json('*.used_on');
        $this->assertSame(['2026-08-12', '2026-08-10'], $usedOnDates);
        $this->assertSame(['approved', 'approved'], $response->json('*.request_status'));
    }
}
