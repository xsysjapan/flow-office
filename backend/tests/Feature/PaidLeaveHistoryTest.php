<?php

namespace Tests\Feature;

use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-P007: 有給履歴を確認する。stored_events(EventStore)を正として、
 * 付与・申請・承認・消化のイベントを時系列で確認できることを検証する。
 */
class PaidLeaveHistoryTest extends TestCase
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

        $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2025-07-01',
            'expires_on' => '2027-06-30',
            'granted_days' => 10,
            'grant_reason' => '初回付与',
        ])->assertCreated();

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $response = $this->actingAs($employee)->getJson('/api/paid-leave/history/mine');
        $response->assertOk();

        $eventTypes = collect($response->json())->pluck('event_type')->all();
        $this->assertSame(
            ['paid_leave.used', 'paid_leave.request_approved', 'paid_leave.requested', 'paid_leave.granted'],
            $eventTypes,
        );

        $usedEvent = collect($response->json())->firstWhere('event_type', 'paid_leave.used');
        $this->assertEquals(1.0, $usedEvent['payload']['used_days']);
        $requestedEvent = collect($response->json())->firstWhere('event_type', 'paid_leave.requested');
        $this->assertSame('full', $requestedEvent['payload']['leave_type']);
    }

    public function test_an_employee_cannot_see_another_employees_history(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->getJson("/api/paid-leave/history/user/{$other->id}")->assertForbidden();
    }

    public function test_admin_and_hr_staff_can_see_any_employees_history(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $this->actingAs($admin)->getJson("/api/paid-leave/history/user/{$employee->id}")
            ->assertOk()
            ->assertJsonCount(0);

        // 手動DB作成(コマンド経由でない)にはイベントが無いため0件だが、権限自体は通ることを確認する。
        $this->actingAs($hr)->getJson("/api/paid-leave/history/user/{$employee->id}")->assertOk();
    }

    public function test_history_only_includes_events_for_the_target_user(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30', 'granted_days' => 10,
        ])->assertCreated();
        $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $other->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30', 'granted_days' => 5,
        ])->assertCreated();

        $response = $this->actingAs($employee)->getJson('/api/paid-leave/history/mine');
        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertEquals($employee->id, $response->json()[0]['payload']['user_id']);
    }
}
