<?php

namespace Tests\Feature;

use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\SpecialLeaveType;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 特別休暇履歴を確認する。stored_events(EventStore)を正として、付与・申請・承認・消化の
 * イベントを時系列で確認できることを検証する(PaidLeaveHistoryTestと同じ考え方)。
 */
class SpecialLeaveHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    public function test_an_employee_can_see_their_own_history_in_chronological_order(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $this->createWorkingDayShift($employee, '2026-08-10');
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 3,
            'grant_reason' => '誕生月付与',
        ])->assertCreated();

        $requestId = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertOk();

        $response = $this->actingAs($employee)->getJson('/api/special-leave/history/mine');
        $response->assertOk();

        $eventTypes = collect($response->json())->pluck('event_type')->all();
        $this->assertSame(
            ['special_leave.used', 'special_leave.request_approved', 'special_leave.requested', 'special_leave.granted'],
            $eventTypes,
        );
    }

    public function test_an_employee_cannot_see_another_employees_history(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->getJson("/api/special-leave/history/user/{$other->id}")->assertForbidden();
    }

    public function test_history_only_includes_events_for_the_target_user(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'granted_days' => 3,
        ])->assertCreated();
        $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $other->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'granted_days' => 2,
        ])->assertCreated();

        $response = $this->actingAs($employee)->getJson('/api/special-leave/history/mine');
        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertEquals($employee->id, $response->json()[0]['payload']['user_id']);
    }
}
