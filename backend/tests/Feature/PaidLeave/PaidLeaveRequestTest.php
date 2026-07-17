<?php

namespace Tests\Feature\PaidLeave;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-P003: 有給を申請する / UC-P004: 有給を承認する。
 */
class PaidLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date, int $prescribedDailyMinutes = 480): EmployeeShiftAssignment
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    public function test_a_full_day_leave_request_is_approved_and_consumes_the_nearest_expiring_grant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '私用のため',
        ]);
        $requestResponse->assertCreated();
        $requestResponse->assertJsonPath('status', 'submitted');
        $requestResponse->assertJsonPath('requested_days', 1);
        $requestId = $requestResponse->json('id');

        $approveResponse = $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve");
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('status', 'approved');

        $grant = PaidLeaveGrant::query()->where('user_id', $employee->id)->first();
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertEquals(9.0, (float) $grant->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
        $this->assertSame('paid_leave_full', $day->work_type);
        $this->assertSame('clocked_out', $day->status);

        $this->assertSame(1, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());
    }

    public function test_a_half_day_leave_request_uses_half_a_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'am_half',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $grant = PaidLeaveGrant::query()->where('user_id', $employee->id)->first();
        $this->assertEquals(0.5, (float) $grant->used_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertSame('paid_leave_am_half', $day->work_type);
    }

    public function test_hourly_leave_requested_days_is_computed_from_prescribed_daily_minutes(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10', prescribedDailyMinutes: 480);

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        // 2時間休 / 8時間(480分)勤務 = 0.25日
        $response = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
        ]);
        $response->assertCreated();
        $this->assertEquals(0.3, $response->json('requested_days'));
    }

    public function test_leave_request_is_rejected_when_the_target_date_is_not_a_working_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_leave_request_is_rejected_when_remaining_balance_is_insufficient(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 0.5, 'used_days' => 0, 'remaining_days' => 0.5,
        ]);

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_approval_consumes_across_multiple_grants_when_the_nearest_expiring_one_is_insufficient(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $soonExpiring = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2024-07-01', 'expires_on' => '2026-12-31',
            'granted_days' => 0.3, 'used_days' => 0, 'remaining_days' => 0.3,
        ]);
        $laterExpiring = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertEquals(0.0, (float) $soonExpiring->refresh()->remaining_days);
        $this->assertEquals(9.3, (float) $laterExpiring->refresh()->remaining_days);
        $this->assertSame(2, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());
    }

    public function test_only_the_designated_approver_can_approve(): void
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

        $this->actingAs($other)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertStatus(422);
    }

    public function test_approver_can_return_a_request_with_a_comment(): void
    {
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

        $response = $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/return", [
            'comment' => '日程を確認してください',
        ]);
        $response->assertOk();
        $response->assertJsonPath('status', 'returned');
    }

    public function test_employee_can_cancel_their_own_submitted_request(): void
    {
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

        $response = $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');
    }

    public function test_my_requests_and_requests_to_approve_list_the_correct_requests(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $this->actingAs($employee)->getJson('/api/paid-leave/requests/mine')->assertOk()->assertJsonCount(1);
        $this->actingAs($approver)->getJson('/api/paid-leave/requests/to-approve')->assertOk()->assertJsonCount(1);
    }
}
