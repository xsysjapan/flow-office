<?php

namespace Tests\Feature\Attendance;

use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Models\AttendanceMonth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 月次勤怠の承認・差戻しはワークフローの申請に対してだけ行う
 * (docs/changesets/20261128-remaining-entrypoints/spec.md 論点8)。
 */
class AttendanceMonthApprovalViaWorkflowOnlyTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use RefreshDatabase;

    private function submittedMonth(User $employee, User $approver): AttendanceMonth
    {
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", ['approver_user_id' => $approver->id])->assertSuccessful();

        return AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();
    }

    public function test_approve_dispatches_only_the_workflow_command_and_approves_the_month(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submittedMonth($employee, $approver);
        $bus = $this->recordCommands();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSingleTopLevelCommand($bus, ApproveWorkflowRequest::class);
    }

    public function test_return_dispatches_only_the_workflow_command_and_returns_the_month(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = $this->submittedMonth($employee, $approver);
        $bus = $this->recordCommands();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/return", ['comment' => '不備'])
            ->assertOk()->assertJsonPath('status', 'returned');

        $this->assertSingleTopLevelCommand($bus, ReturnWorkflowRequest::class);
    }

    public function test_submitted_month_without_workflow_request_is_rejected_with_422(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $month = AttendanceMonth::query()->create(['user_id' => $employee->id, 'year_month' => '2026-05', 'status' => 'submitted']);
        $events = DB::table('stored_events')->count();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/approve")
            ->assertUnprocessable()->assertJsonPath('message', 'この月の申請が見つかりません。');
        $this->actingAs($approver)->postJson("/api/attendance-months/{$month->id}/return", ['comment' => '不備'])
            ->assertUnprocessable();

        $this->assertSame('submitted', $month->refresh()->status);
        $this->assertSame($events, DB::table('stored_events')->count());
    }
}
