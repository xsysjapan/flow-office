<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 特別休暇を申請する / 承認する。有給休暇(PaidLeaveRequestTest)と同じ申請・承認・消化の
 * 流れだが、ビジネスロジックは独立したApp\Domain\SpecialLeaveとして実装されている。
 */
class SpecialLeaveRequestTest extends TestCase
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

    private function createType(string $name = '誕生日休暇'): SpecialLeaveType
    {
        return SpecialLeaveType::query()->create(['name' => $name, 'is_active' => true]);
    }

    public function test_a_full_day_request_is_approved_and_consumes_the_grant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $requestResponse = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '誕生日のため',
        ]);
        $requestResponse->assertCreated();
        $requestResponse->assertJsonPath('status', 'submitted');
        $requestId = $requestResponse->json('id');

        $approveResponse = $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve");
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('status', 'approved');

        $grant = SpecialLeaveGrant::query()->where('user_id', $employee->id)->first();
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertEquals(2.0, (float) $grant->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
        $this->assertSame('special_leave_full', $day->work_type);
        $this->assertSame('clocked_out', $day->status);
        $this->assertSame(1, SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->count());

        $this->assertEquals(1.0, $day->calculation->special_leave_days);
    }

    public function test_hourly_special_leave_is_reflected_in_the_daily_calculation_minutes(): void
    {
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
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertSame('special_leave_hourly', $day->work_type);
        $this->assertEquals(120, $day->calculation->special_leave_minutes);
        $this->assertEquals(0.0, (float) $day->calculation->special_leave_days);
    }

    public function test_request_is_rejected_when_the_special_leave_type_balance_is_insufficient(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 0.5, 'used_days' => 0, 'remaining_days' => 0.5,
        ]);

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_request_consumes_across_multiple_grants_preferring_the_one_expiring_soonest_and_using_the_non_expiring_one_last(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $neverExpires = SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2025-07-01', 'expires_on' => null,
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);
        $expiringSoon = SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => '2026-12-31',
            'granted_days' => 0.3, 'used_days' => 0, 'remaining_days' => 0.3,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertEquals(0.0, (float) $expiringSoon->refresh()->remaining_days);
        $this->assertEquals(9.3, (float) $neverExpires->refresh()->remaining_days);
    }

    public function test_a_special_leave_request_is_rejected_when_a_paid_leave_request_already_exists_on_the_same_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
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

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_a_paid_leave_request_is_rejected_when_a_special_leave_request_already_exists_on_the_same_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);
        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_balances_are_scoped_per_special_leave_type(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $birthday = $this->createType('誕生日休暇');
        $refresh = $this->createType('リフレッシュ休暇');
        $this->createWorkingDayShift($employee, '2026-08-10');

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $birthday->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);
        // リフレッシュ休暇の残高は無い(0件)。

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $refresh->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_only_the_designated_approver_can_approve(): void
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

        $this->actingAs($other)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertStatus(422);
    }

    public function test_approver_can_return_a_request_with_a_comment(): void
    {
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

        $response = $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/return", [
            'comment' => '日程を確認してください',
        ]);
        $response->assertOk();
        $response->assertJsonPath('status', 'returned');
    }

    public function test_employee_can_cancel_their_own_submitted_request(): void
    {
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

        $response = $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');
    }

    public function test_my_requests_and_requests_to_approve_list_the_correct_requests(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');
        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $this->actingAs($employee)->getJson('/api/special-leave/requests/mine')->assertOk()->assertJsonCount(1);
        $this->actingAs($approver)->getJson('/api/special-leave/requests/to-approve')->assertOk()->assertJsonCount(1);
    }
}
