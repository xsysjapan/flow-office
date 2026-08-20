<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 締め済み・確定済み勤怠を修正するための救済コマンド。
 * - ReopenClosedAttendanceMonth (管理者専用): closed → approved
 * - RevertApprovedAttendanceMonth (バックオフィス担当者専用): approved → not_submitted
 */
class AttendanceMonthRescueCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function submitAndApprove(User $employee, User $approver): AttendanceMonth
    {
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        return $month->refresh();
    }

    public function test_admin_can_reopen_a_closed_month(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);

        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/close")
            ->assertOk()->assertJsonPath('status', 'closed');

        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => '打刻の記載漏れが見つかったため締めを取り消す',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $month->refresh()->status);
        $this->assertNull($month->refresh()->closed_at);
    }

    public function test_reopen_is_forbidden_for_non_admin(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);
        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/close")->assertOk();

        // approver自身(管理者ロールなし)は締め取消できない。
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => '権限のないユーザーによる取消(拒否されるべき)',
        ])->assertStatus(403);
    }

    public function test_reopen_fails_when_month_is_not_closed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $month = $this->submitAndApprove($employee, $approver);

        // まだ締めていない(approved)状態でreopenは呼べない。
        $this->actingAs($admin)->postJson("/api/attendance-months/{$month->id}/reopen", [
            'reason' => 'まだ締めていない月',
        ])->assertStatus(422);
    }

    public function test_backoffice_staff_can_revert_an_approved_month_confirmation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $backofficeStaff = User::factory()->create();
        $this->assignRole($backofficeStaff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        $month = $this->submitAndApprove($employee, $approver);

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($backofficeStaff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '打刻修正のため確定を取り消す',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertOk()->assertJsonPath('status', 'not_submitted');

        $month->refresh();
        $this->assertSame('not_submitted', $month->status);
        $this->assertNull($month->approved_at);
        $this->assertNull($month->submitted_at);
    }

    public function test_revert_confirmation_is_forbidden_for_non_backoffice_staff(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $month = $this->submitAndApprove($employee, $approver);

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $month->year_month, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        // 承認者自身(バックオフィス担当ロールなし)は確定取消できない。
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => '権限のないユーザーによる取消(拒否されるべき)',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertStatus(403);
    }

    public function test_revert_confirmation_fails_when_month_is_not_approved(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $backofficeStaff = User::factory()->create();
        $this->assignRole($backofficeStaff, Role::query()->create(['code' => Role::BACKOFFICE_STAFF, 'name' => 'バックオフィス担当']));

        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');
        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $workflowRequest = WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'title' => '勤怠確定取消依頼',
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'status' => WorkflowRequestStatus::APPROVED,
            'form_data' => ['target_year_month' => $yearMonth, 'reason' => '打刻修正のため'],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        // まだ承認されていない(submitted)状態ではrevert-confirmationは呼べない。
        $this->actingAs($backofficeStaff)->postJson("/api/attendance-months/{$month->id}/revert-confirmation", [
            'reason' => 'まだ承認されていない月',
            'workflow_request_id' => $workflowRequest->id,
        ])->assertStatus(422);
    }
}
