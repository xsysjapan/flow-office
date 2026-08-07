<?php

namespace Tests\Feature\CompensatoryLeave;

use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveUsage;
use App\Models\EmployeeShiftAssignment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 代休(App\Domain\CompensatoryLeave)。休日出勤の勤怠実績から自動導出されるGrantの生成・
 * 取消・月次確定・消化申請・承認・取消申請までの一連の流れを検証する。
 */
class CompensatoryLeaveTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(int $prescribedDailyMinutes = 480): WorkStyle
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function makeHolidayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true,
            'planned_break_minutes' => 0,
        ]);
    }

    private function makeWorkingDayShift(User $user, WorkStyle $workStyle, string $date): void
    {
        EmployeeShiftAssignment::query()->create([
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

    public function test_holiday_work_creates_a_draft_grant_under_the_daily_unit(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');

        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00', [['start' => '2026-08-08T12:00:00+09:00', 'end' => '2026-08-08T13:00:00+09:00']]);

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('draft', $grant->status);
        $this->assertEquals(1.0, (float) $grant->granted_days);
        $this->assertNull($grant->granted_minutes);
        $this->assertEquals(1.0, (float) $grant->remaining_days);
    }

    public function test_half_day_unit_grants_a_full_or_half_day_depending_on_the_threshold(): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => 'half_day',
            'compensatory_leave_half_day_threshold_minutes' => 240,
        ]);

        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->makeHolidayShift($employee, $workStyle, '2026-08-09');

        // ちょうど閾値(240分)は超過ではないため0.5日。
        $this->recordAttendance($employee, '2026-08-08', '09:00', '13:00');
        // 閾値超過(480分)のため1.0日。
        $this->recordAttendance($employee, '2026-08-09', '09:00', '18:00', [['start' => '2026-08-09T12:00:00+09:00', 'end' => '2026-08-09T13:00:00+09:00']]);

        $thresholdGrant = CompensatoryLeaveGrant::query()->whereDate('work_date', '2026-08-08')->firstOrFail();
        $this->assertEquals(0.5, (float) $thresholdGrant->granted_days);

        $overThresholdGrant = CompensatoryLeaveGrant::query()->whereDate('work_date', '2026-08-09')->firstOrFail();
        $this->assertEquals(1.0, (float) $overThresholdGrant->granted_days);
    }

    public function test_hourly_unit_grants_the_worked_minutes(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'hourly']);

        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');

        $this->recordAttendance($employee, '2026-08-08', '09:00', '12:00');

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $grant->granted_days);
        $this->assertSame(180, $grant->granted_minutes);
    }

    public function test_editing_the_day_so_it_is_no_longer_holiday_work_removes_the_draft_grant(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => 'daily']);

        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-08')->firstOrFail();
        $this->assertSame(1, CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->count());

        $this->actingAs($employee)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => null,
            'actual_end_at' => null,
            'breaks' => [],
            'reason' => '実績取消',
        ])->assertOk();

        $this->assertSame(0, CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->count());
    }

    public function test_compensatory_leave_disabled_does_not_create_a_grant(): void
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => false]);

        $employee = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $this->assertSame(0, CompensatoryLeaveGrant::query()->count());
    }

    public function test_submitting_the_month_confirms_draft_grants_with_expiry_when_valid_days_is_set(): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => 'daily',
            'compensatory_leave_valid_days' => 60,
        ]);

        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('confirmed', $grant->status);
        $this->assertNotNull($grant->confirmed_at);
        $this->assertNotNull($grant->expires_on);
        $this->assertEquals(
            $grant->confirmed_at->copy()->addDays(60)->toDateString(),
            $grant->expires_on->toDateString(),
        );
    }

    public function test_submitting_the_month_confirms_draft_grants_without_expiry_when_valid_days_is_disabled(): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => 'daily',
            'compensatory_leave_valid_days' => null,
        ]);

        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, '2026-08-08');
        $this->recordAttendance($employee, '2026-08-08', '09:00', '17:00');

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('confirmed', $grant->status);
        $this->assertNull($grant->expires_on);
    }

    private function confirmedGrant(User $employee, string $workDate = '2026-08-08', string $unit = 'daily'): CompensatoryLeaveGrant
    {
        SystemSetting::current()->update(['compensatory_leave_enabled' => true, 'compensatory_leave_unit' => $unit]);

        $workStyle = $this->makeWorkStyle();
        $this->makeHolidayShift($employee, $workStyle, $workDate);
        $this->recordAttendance($employee, $workDate, '09:00', '17:00', [['start' => "{$workDate}T12:00:00+09:00", 'end' => "{$workDate}T13:00:00+09:00"]]);

        $approver = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        return CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
    }

    public function test_a_confirmed_grant_can_be_consumed_via_request_and_approval(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        // 消化する代休の対象日は、Grantの元になった月(2026-08、既に月次提出済み)とは別の月に
        // する。提出済み月に属する日次勤怠はAttendanceEditGuardにより編集(承認時の
        // work_type反映)が禁止されるため(通常の運用でも、稼いだ代休を後日の別月に
        // 消化するのが自然な流れ)。
        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestResponse = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ]);
        $requestResponse->assertCreated();
        $requestResponse->assertJsonPath('status', 'submitted');
        $requestId = $requestResponse->json('id');

        $approveResponse = $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve");
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('status', 'approved');

        $grant = CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertEquals(1.0, (float) $grant->used_days);
        $this->assertEquals(0.0, (float) $grant->remaining_days);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->firstOrFail();
        $this->assertSame('compensatory_leave_full', $day->work_type);
    }

    public function test_leave_type_is_restricted_by_the_configured_unit(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee, '2026-08-08', 'daily');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-08-20');

        // unit=dailyの設定ではhourlyは使用できない。
        $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-08-20',
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_an_unused_grant_can_be_cancelled_but_a_partially_used_grant_cannot(): void
    {
        SystemSetting::current()->update(['compensatory_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $unusedGrant = $this->confirmedGrant($employee, '2026-08-08');

        $cancelResponse = $this->actingAs($employee)->postJson("/api/compensatory-leave/grants/{$unusedGrant->id}/request-cancellation", [
            'reason' => '出勤日を勘違いしていたため',
        ]);
        $cancelResponse->assertOk();
        $this->assertSame('cancelled', $unusedGrant->refresh()->status);
        $this->assertEquals(0.0, (float) $unusedGrant->remaining_days);

        // 一部でも使用された場合は取消できない(別の社員で検証。同じ社員で2度目の
        // 月次提出を行うと「提出済み以降は再提出できない」で弾かれてしまうため)。
        $anotherEmployee = User::factory()->create();
        $usedGrant = $this->confirmedGrant($anotherEmployee, '2026-08-09');
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($anotherEmployee, $workStyle, '2026-09-11');

        $requestId = $this->actingAs($anotherEmployee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-11',
            'leave_type' => 'full',
            'reason' => '代休消化',
        ])->assertCreated()->json('id');
        $this->assertSame('approved', CompensatoryLeaveRequest::query()->findOrFail($requestId)->status);

        $blockedResponse = $this->actingAs($anotherEmployee)->postJson("/api/compensatory-leave/grants/{$usedGrant->id}/request-cancellation");
        $blockedResponse->assertStatus(422);
    }

    public function test_cannot_request_cancellation_of_another_employees_grant(): void
    {
        SystemSetting::current()->update(['compensatory_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $grant = $this->confirmedGrant($employee, '2026-08-08');

        $anotherEmployee = User::factory()->create();

        $this->actingAs($anotherEmployee)
            ->postJson("/api/compensatory-leave/grants/{$grant->id}/request-cancellation")
            ->assertStatus(422);

        $this->assertSame('confirmed', $grant->refresh()->status);
    }

    public function test_requires_approval_false_confirms_the_request_immediately(): void
    {
        SystemSetting::current()->update(['compensatory_leave_requires_approval' => false]);

        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestResponse = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'reason' => '代休消化',
        ]);
        $requestResponse->assertCreated();
        $requestResponse->assertJsonPath('status', 'approved');

        $requestId = $requestResponse->json('id');
        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());
    }

    /**
     * PaidLeaveと同様、代休の消化申請もDraftWorkflowRequest(subjectType:
     * 'compensatory_leave_request')経由でworkflow_requestと連携する。
     */
    public function test_storing_a_request_creates_a_workflow_request_pointing_at_it(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'compensatory_leave_request')->firstOrFail();
        $this->assertSame($requestId, $workflowRequest->subject_id);
    }

    /**
     * バグ修正の直接確認: GET /api/workflow-requests/{id} で代休申請のsubjectが
     * 正しく返ること(WorkflowRequest::subjectModel()/WorkflowRequestResource::
     * buildSubjectSummary()/WorkflowRequestController::show()の3箇所に
     * compensatory_leave_requestのcaseが必要)。
     */
    public function test_workflow_request_show_returns_the_compensatory_leave_request_subject(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $requestId)->firstOrFail();

        $response = $this->actingAs($approver)->getJson("/api/workflow-requests/{$workflowRequest->id}");
        $response->assertOk();
        $response->assertJsonPath('subject.type', 'compensatory_leave_request');
        $response->assertJsonPath('subject.id', $requestId);
        $response->assertJsonPath('subject.target_date', '2026-09-10');
        $response->assertJsonPath('subject.leave_type', 'full');
        $response->assertJsonPath('subject.leave_type_label', '全休');
        $response->assertJsonPath('subject.requested_days', 1);
        $response->assertJsonPath('subject.reason', '代休消化');

        $response->assertJsonPath('subject_summary.target_date', '2026-09-10');
        $response->assertJsonPath('subject_summary.leave_type_label', '全休');
        $response->assertJsonPath('subject_summary.requested_days', 1);
    }

    public function test_cancelling_an_approved_full_day_request_restores_the_grant_and_clears_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $grant = $this->confirmedGrant($employee);

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $this->assertEquals(0.0, (float) $grant->refresh()->remaining_days);
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first();
        $this->assertSame('compensatory_leave_full', $day->work_type);

        $response = $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel");
        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
        $this->assertSame(0, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());

        $day->refresh();
        $this->assertNull($day->work_type);
        $this->assertSame('not_started', $day->status);
    }

    /**
     * 半休は実際の出退勤(打刻)が既にあるため、取消時にステータスは打刻由来のまま維持する
     * (全休のようにclocked_out扱いへ強制していないため巻き戻し不要。PaidLeaveRequestTestの
     * 同名テストと同じ考え方)。
     */
    public function test_cancelling_an_approved_half_day_request_keeps_the_actual_punch_derived_status(): void
    {
        $employee = User::factory()->create();
        $grant = $this->confirmedGrant($employee, '2026-08-08', 'half_day');

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'am_half',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->first();
        $day->update(['actual_start_at' => '2026-09-10 13:00:00', 'actual_end_at' => '2026-09-10 18:00:00', 'status' => 'clocked_out']);

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")->assertOk();

        $this->assertEquals(1.0, (float) $grant->refresh()->remaining_days);
        $day->refresh();
        $this->assertNull($day->work_type);
        $this->assertSame('clocked_out', $day->status);
    }

    public function test_cannot_cancel_an_approved_request_once_the_month_is_submitted(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/compensatory-leave/requests/{$requestId}/approve")->assertOk();

        $monthApprover = User::factory()->create();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-09/submit', [
            'approver_user_id' => $monthApprover->id,
        ])->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")->assertStatus(422);

        $this->assertSame('approved', CompensatoryLeaveRequest::query()->findOrFail($requestId)->status);
    }

    public function test_cancelling_a_request_also_cancels_the_workflow_request(): void
    {
        $employee = User::factory()->create();
        $this->confirmedGrant($employee);

        $approver = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->makeWorkingDayShift($employee, $workStyle, '2026-09-10');

        $requestId = $this->actingAs($employee)->postJson('/api/compensatory-leave/requests', [
            'target_date' => '2026-09-10',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
            'reason' => '代休消化',
        ])->assertCreated()->json('id');

        $this->actingAs($employee)->postJson("/api/compensatory-leave/requests/{$requestId}/cancel")->assertOk();

        $workflowRequest = WorkflowRequest::query()->where('subject_id', $requestId)->firstOrFail();
        $this->assertSame('cancelled', $workflowRequest->status);
    }
}
