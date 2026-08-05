<?php

namespace Tests\Feature\SpecialLeave;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        $workflowRequestId = WorkflowRequest::query()
            ->where('subject_type', 'special_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');

        // 承認依頼の通知には、統合承認一覧の該当明細へのリンクが付く。
        $approverNotifications = $this->actingAs($approver)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith("/approvals?requestId={$workflowRequestId}", $approverNotifications[0]['detail_url']);

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

    /**
     * requires_grant=falseの種別(忌引・代休等)は、事前の付与(SpecialLeaveGrant)が
     * 1件も無くても申請・承認でき、勤怠にも反映される。
     */
    public function test_a_type_without_requires_grant_can_be_requested_and_approved_without_any_grant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '代休', 'is_active' => true, 'requires_grant' => false]);
        $this->createWorkingDayShift($employee, '2026-08-10');

        $requestResponse = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '休日出勤の代休',
        ]);
        $requestResponse->assertCreated();
        $requestId = $requestResponse->json('id');

        $approveResponse = $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve");
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('status', 'approved');

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
        $this->assertSame('special_leave_full', $day->work_type);
        $this->assertSame(0, SpecialLeaveGrant::query()->where('user_id', $employee->id)->count());
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

    /**
     * 通常勤務は運用上employee_shift_assignmentsが事前展開されないことが多いため、
     * 勤務予定が無くてもシステムのデフォルト働き方(カレンダー未設定)から平日を
     * 所定労働日とみなして申請できる(ScheduledWorkingDayResolver参照)。
     */
    public function test_a_request_without_a_shift_assignment_succeeds_on_a_weekday_when_the_default_work_style_has_no_calendar(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $defaultWorkStyle = WorkStyle::query()->create([
            'code' => 'default-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => null, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $defaultWorkStyle->id]);

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        // 2026-08-10は月曜日(平日)。employee_shift_assignmentsの行は無い。
        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();
    }

    public function test_a_request_without_a_shift_assignment_is_rejected_on_a_weekend_when_the_default_work_style_has_no_calendar(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $type = $this->createType();
        $defaultWorkStyle = WorkStyle::query()->create([
            'code' => 'default-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => null, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $defaultWorkStyle->id]);

        SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01', 'expires_on' => null,
            'granted_days' => 3, 'used_days' => 0, 'remaining_days' => 3,
        ]);

        // 2026-08-15は土曜日。
        $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-15',
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

        // 差戻し通知には、特別休暇申請の履歴画面へのリンクが付く。
        $employeeNotifications = $this->actingAs($employee)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith('/special-leave/history', $employeeNotifications[0]['detail_url']);
    }

    /**
     * system_settings.special_leave_requires_approval=falseの場合、承認ワークフローを経由せず
     * 申請と同時に自動承認(消化)まで完結する。
     */
    public function test_when_approval_is_not_required_the_request_is_auto_approved_without_a_workflow_request(): void
    {
        SystemSetting::current()->update(['special_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $grant = SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'approved');
        $requestId = $response->json('id');

        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());
        $this->assertEquals(9.0, (float) $grant->refresh()->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
    }

    /**
     * 承認不要設定でも残数不足なら422を返し、SpecialLeaveRequest行が孤立して残らないこと。
     */
    public function test_when_approval_is_not_required_insufficient_balance_returns_422_without_an_orphan_request(): void
    {
        SystemSetting::current()->update(['special_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $type = $this->createType();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $grant = SpecialLeaveGrant::query()->create([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 0.5, 'used_days' => 0, 'remaining_days' => 0.5,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/special-leave/requests', [
            'special_leave_type_id' => $type->id,
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SpecialLeaveRequest::query()->count());
        $this->assertEquals(0.5, (float) $grant->refresh()->remaining_days);
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

    /**
     * workflow_requests.subject_id はイベント(WorkflowRequestDrafted)から投影されるため、
     * Projectionを再生成しても失われない(ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
     * 特別休暇申請自体もSpecialLeaveRequestedイベントだけで submitted に復元できる。
     */
    public function test_replaying_the_event_store_keeps_the_workflow_request_link_and_the_submitted_status(): void
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

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'special_leave_request')->firstOrFail();
        $this->assertSame($requestId, $workflowRequest->subject_id);

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($requestId, $workflowRequest->refresh()->subject_id);

        $specialLeaveRequest = SpecialLeaveRequest::query()->findOrFail($requestId);
        $this->assertSame('submitted', $specialLeaveRequest->status);
        $this->assertNotNull($specialLeaveRequest->submitted_at);
    }

    public function test_store_request_returns_the_created_request_that_the_workflow_request_points_at(): void
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

        $this->assertNotNull($requestId);

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'special_leave_request')->firstOrFail();
        $this->assertSame($workflowRequest->subject_id, $requestId);
    }

    /**
     * 対応するworkflow_requestが無い場合、承認は黙って何もせず200を返してはいけない。
     */
    public function test_approval_fails_when_there_is_no_corresponding_workflow_request(): void
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

        WorkflowRequest::query()->where('subject_id', $requestId)->delete();

        $this->actingAs($approver)->postJson("/api/special-leave/requests/{$requestId}/approve")->assertStatus(422);

        $this->assertSame('submitted', SpecialLeaveRequest::query()->findOrFail($requestId)->status);
    }

    public function test_cancelling_a_request_also_cancels_the_workflow_request(): void
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

        $this->actingAs($employee)->postJson("/api/special-leave/requests/{$requestId}/cancel")->assertOk();

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $requestId)->firstOrFail();
        $this->assertSame('cancelled', $workflowRequest->status);
    }
}
