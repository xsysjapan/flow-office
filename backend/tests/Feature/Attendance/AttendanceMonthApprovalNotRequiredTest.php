<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * system_settings.attendance_requires_approval のトグル。UC-A008/UC-A009。
 * ExpenseClaimのapproval_skip_thresholdによる自動承認と同じ仕組みで、
 * SubmitAttendanceMonthHandlerが提出と同時にAttendanceMonthAggregate::approve(null)を発行し、
 * ApproveWorkflowRequestOnAttendanceMonthApprovedReactorがworkflow_request側を同期する。
 */
class AttendanceMonthApprovalNotRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_when_approval_is_not_required_submitting_without_an_approver_auto_approves_the_month(): void
    {
        SystemSetting::current()->update(['attendance_requires_approval' => false]);

        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)
            ->postJson("/api/attendance/months/{$yearMonth}/submit", [])
            ->assertSuccessful()
            ->assertJsonPath('status', 'approved');

        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();
        $this->assertSame('approved', $month->status);

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'attendance_month')->where('subject_id', $month->id)->first();
        $this->assertNotNull($workflowRequest);
        $this->assertSame('approved', $workflowRequest->status);
    }

    public function test_when_approval_is_required_submitting_without_an_approver_fails_validation(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)
            ->postJson("/api/attendance/months/{$yearMonth}/submit", [])
            ->assertStatus(422);
    }

    public function test_when_approval_is_required_the_normal_approver_driven_flow_still_works(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful()->assertJsonPath('status', 'submitted');

        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $this->actingAs($approver)
            ->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertSuccessful()
            ->assertJsonPath('status', 'approved');

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'attendance_month')->where('subject_id', $month->id)->firstOrFail();
        $this->assertSame('approved', $workflowRequest->status);
    }
}
