<?php

namespace Tests\Feature\PaidLeave;

use App\Models\AttendanceDay;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * UC-P003: 有給を申請する / UC-P004: 有給を承認する。
 */
class PaidLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date, int $prescribedDailyMinutes = 480): EmployeeCalendarEntry
    {
        $calendar = CompanyCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        return EmployeeCalendarEntry::query()->create([
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

        $workflowRequestId = WorkflowRequest::query()
            ->where('subject_type', 'paid_leave_request')
            ->where('subject_id', $requestId)
            ->value('id');

        // 承認依頼の通知には、統合承認一覧の該当明細へのリンクが付く。
        $approverNotifications = $this->actingAs($approver)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith("/approvals?requestId={$workflowRequestId}", $approverNotifications[0]['detail_url']);

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

    /**
     * 通常勤務は運用上employee_calendar_entriesが事前展開されないことが多いため、
     * 勤務予定が無くてもシステムのデフォルト働き方(カレンダー未設定)から平日を
     * 所定労働日とみなして申請できる(ScheduledWorkingDayResolver参照)。
     */
    public function test_a_leave_request_without_a_calendar_entry_succeeds_on_a_weekday_when_the_default_work_style_has_no_calendar(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $defaultWorkStyle = WorkStyle::query()->create([
            'code' => 'default-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => null, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $defaultWorkStyle->id]);

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        // 2026-08-10は月曜日(平日)。employee_calendar_entriesの行は無い。
        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();
    }

    public function test_a_leave_request_without_a_calendar_entry_is_rejected_on_a_weekend_when_the_default_work_style_has_no_calendar(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $defaultWorkStyle = WorkStyle::query()->create([
            'code' => 'default-'.$employee->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => null, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $defaultWorkStyle->id]);

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        // 2026-08-15は土曜日。
        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-15',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    /**
     * 残数不足でも申請(=対象日の勤怠への反映)自体は成立させる方針
     * (勤怠を先に作る/編集するという通常の業務フローに合わせ、承認を待たない)。
     * 残数消費は承認時のみ発生するため、この時点ではgrantのremaining_daysは変化しない。
     */
    public function test_leave_request_succeeds_and_reflects_on_attendance_even_when_remaining_balance_is_insufficient(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 0.5, 'used_days' => 0, 'remaining_days' => 0.5,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'submitted');

        $this->assertEquals(0.5, (float) $grant->refresh()->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
        $this->assertSame('paid_leave_full', $day->work_type);
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

        // 差戻し通知には、有給申請の履歴画面へのリンクが付く。
        $employeeNotifications = $this->actingAs($employee)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith('/paid-leave/history', $employeeNotifications[0]['detail_url']);
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

        // 未承認の取消でも、申請時点で反映済みの勤怠(attendance_days.work_type)と
        // 未確定のpaid_leave_usages行は巻き戻される(承認済みの取消と同じ巻き戻しが必要)。
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertSame('paid_leave_full', $day->work_type);
        $this->assertSame(1, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());

        $response = $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $day->refresh();
        $this->assertNull($day->work_type);
        $this->assertSame(0, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());
    }

    /**
     * 申請時点(承認前)でpaid_leave_usagesに未確定(grant_id未設定・is_confirmed=false)の
     * 行が作られること、承認時にその同じ行が確定済み(grant_id設定・is_confirmed=true)へ
     * 更新されることを検証する(PaidLeaveUsageProjector参照)。勤怠側はこの行だけで
     * 「休暇が設定されているか」「確定済みか」を判定でき、paid_leave_requestsを見に行く
     * 必要が無い。
     */
    public function test_a_usage_row_is_designated_at_request_time_and_confirmed_at_approval(): void
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

        $usage = PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->firstOrFail();
        $this->assertFalse($usage->is_confirmed);
        $this->assertNull($usage->paid_leave_grant_id);
        $this->assertEquals(1.0, (float) $usage->used_days);

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $usage->refresh();
        $this->assertTrue($usage->is_confirmed);
        $this->assertNotNull($usage->paid_leave_grant_id);
        // 同じ行が更新されただけで、新規行は増えていないこと(1申請1grantで完結する場合)。
        $this->assertSame(1, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());
    }

    public function test_cancelling_an_approved_full_day_request_restores_the_grant_and_clears_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertEquals(9.0, (float) $grant->refresh()->remaining_days);
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertSame('paid_leave_full', $day->work_type);

        $response = $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $this->assertEquals(10.0, (float) $grant->refresh()->remaining_days);
        $this->assertSame(0, PaidLeaveUsage::query()->where('paid_leave_request_id', $requestId)->count());

        $day->refresh();
        $this->assertNull($day->work_type);
        $this->assertSame('not_started', $day->status);
    }

    /**
     * 半休は実際の出退勤(打刻)が既にあるため、取消時にステータスは打刻由来のまま維持する
     * (全休のようにclocked_out扱いへ強制していないため巻き戻し不要)。
     */
    public function test_cancelling_an_approved_half_day_request_keeps_the_actual_punch_derived_status(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'am_half',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $day->update(['actual_start_at' => '2026-08-10 13:00:00', 'actual_end_at' => '2026-08-10 18:00:00', 'status' => 'clocked_out']);

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        $this->assertEquals(10.0, (float) $grant->refresh()->remaining_days);
        $day->refresh();
        $this->assertNull($day->work_type);
        $this->assertSame('clocked_out', $day->status);
    }

    public function test_cannot_cancel_an_approved_request_once_the_month_is_submitted(): void
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
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $monthApprover = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $monthApprover->id,
        ])->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->assertSame('approved', PaidLeaveRequest::query()->findOrFail($requestId)->status);
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

    /**
     * workflow_requests.subject_id はイベント(WorkflowRequestDrafted)から投影されるため、
     * Projectionを再生成しても失われない(ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
     * 有給申請自体もPaidLeaveRequestedイベントだけで submitted に復元できる。
     */
    public function test_replaying_the_event_store_keeps_the_workflow_request_link_and_the_submitted_status(): void
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

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'paid_leave_request')->firstOrFail();
        $this->assertSame($requestId, $workflowRequest->subject_id);

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($requestId, $workflowRequest->refresh()->subject_id);

        $paidLeaveRequest = PaidLeaveRequest::query()->findOrFail($requestId);
        $this->assertSame('submitted', $paidLeaveRequest->status);
        $this->assertNotNull($paidLeaveRequest->submitted_at);
    }

    public function test_store_request_returns_the_created_request_that_the_workflow_request_points_at(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated();

        $requestId = $response->json('id');
        $this->assertNotNull($requestId);

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'paid_leave_request')->firstOrFail();
        $this->assertSame($workflowRequest->subject_id, $requestId);
    }

    /**
     * 対応するworkflow_requestが無い場合、承認は黙って何もせず200を返してはいけない。
     */
    public function test_approval_fails_when_there_is_no_corresponding_workflow_request(): void
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

        WorkflowRequest::query()->where('subject_id', $requestId)->delete();

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertStatus(422);

        $this->assertSame('submitted', PaidLeaveRequest::query()->findOrFail($requestId)->status);
    }

    /**
     * system_settings.paid_leave_requires_approval=falseの場合、承認ワークフローを経由せず
     * 申請と同時に自動承認(消化)まで完結する。
     */
    public function test_when_approval_is_not_required_the_request_is_auto_approved_without_a_workflow_request(): void
    {
        SystemSetting::current()->update(['paid_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
            'reason' => '私用のため',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'approved');
        $requestId = $response->json('id');

        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());

        $this->assertEquals(9.0, (float) $grant->refresh()->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-10')->first();
        $this->assertNotNull($day);
        $this->assertSame('paid_leave_full', $day->work_type);
    }

    /**
     * 承認不要設定の場合、残数不足でも即時承認(消化計画で消化できる分だけ記録)まで成立する
     * (残数不足で申請・承認自体をブロックしない方針。RequestPaidLeave→
     * ApprovePaidLeaveRequestの2段発行はそのまま1トランザクションで包まれる)。
     */
    public function test_when_approval_is_not_required_insufficient_balance_still_auto_approves_with_partial_consumption(): void
    {
        SystemSetting::current()->update(['paid_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 0.5, 'used_days' => 0, 'remaining_days' => 0.5,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'full',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'approved');
        $this->assertSame(1, PaidLeaveRequest::query()->count());
        $this->assertEquals(0.0, (float) $grant->refresh()->remaining_days);
    }

    public function test_cancelling_a_request_also_cancels_the_workflow_request(): void
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

        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$requestId}/cancel")->assertOk();

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $requestId)->firstOrFail();
        $this->assertSame('cancelled', $workflowRequest->status);
    }
}
