<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AttendanceMonthCancelOnWorkflowRequestCancelledReactorが無かった期間に、申請者自身が
 * workflow_requestを取り消し、attendance_monthsが提出済み/差戻し済みのまま取り残された行を
 * `attendance:repair-cancelled-months`で修復できることを確認する。
 */
class RepairCancelledAttendanceMonthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairs_a_month_stuck_as_submitted_behind_a_cancelled_request(): void
    {
        $employee = User::factory()->create();

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);

        WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $employee->id,
            'title' => '2026-06 月次勤怠',
            'status' => 'cancelled',
            'form_data' => [],
            'subject_type' => 'attendance_month',
            'subject_id' => $month->id,
            'submitted_at' => now(),
            'cancelled_at' => now(),
        ]);

        $this->artisan('attendance:repair-cancelled-months')->assertSuccessful();

        $this->assertSame('not_submitted', $month->fresh()->status);
    }

    public function test_dry_run_does_not_change_anything(): void
    {
        $employee = User::factory()->create();

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);

        WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $employee->id,
            'title' => '2026-06 月次勤怠',
            'status' => 'cancelled',
            'form_data' => [],
            'subject_type' => 'attendance_month',
            'subject_id' => $month->id,
            'submitted_at' => now(),
            'cancelled_at' => now(),
        ]);

        $this->artisan('attendance:repair-cancelled-months --dry-run')->assertSuccessful();

        $this->assertSame('submitted', $month->fresh()->status);
    }

    public function test_does_not_touch_a_month_with_an_active_request(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $approver->id,
            'submitted_at' => now(),
        ]);

        WorkflowRequest::query()->create([
            'id' => (string) Str::uuid(),
            'applicant_user_id' => $employee->id,
            'approver_user_id' => $approver->id,
            'title' => '2026-06 月次勤怠',
            'status' => 'submitted',
            'form_data' => [],
            'subject_type' => 'attendance_month',
            'subject_id' => $month->id,
            'submitted_at' => now(),
        ]);

        $this->artisan('attendance:repair-cancelled-months')->assertSuccessful();

        $this->assertSame('submitted', $month->fresh()->status);
    }
}
