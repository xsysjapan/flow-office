<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\BackOfficeTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-A011の変更: 月次勤怠が承認されたら、管理部の一括締め処理を待たずに人事部の
 * バックオフィスタスクを自動作成する(経費精算のCreateBackOfficeTaskOnExpenseClaimApproval
 * Reactorと同じパターン)。
 */
class AttendanceMonthApprovalBackOfficeTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_a_month_creates_a_backoffice_task_for_hr(): void
    {
        $employee = User::factory()->create(['name' => '山田太郎']);
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail()->id;

        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $task = BackOfficeTask::query()->where('source_type', 'attendance_month')->where('source_id', $monthId)->first();
        $this->assertNotNull($task, 'バックオフィスタスクが自動生成されていること');
        $this->assertSame('attendance_month_confirmation', $task->task_type);
        $this->assertSame('人事部', $task->assigned_department);
        $this->assertSame('not_started', $task->status);
        $this->assertStringContainsString('山田太郎', $task->title);
        $this->assertStringContainsString($yearMonth, $task->title);
    }

    /**
     * GET /attendance-months/{id}: バックオフィスタスクからのリンク先として、
     * id指定で単一の月次勤怠を軽量に取得できる。
     */
    public function test_can_fetch_a_single_attendance_month_by_id(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail()->id;

        $response = $this->actingAs($approver)->getJson("/api/attendance-months/{$monthId}");

        $response->assertOk()
            ->assertJsonPath('id', $monthId)
            ->assertJsonPath('user_id', $employee->id)
            ->assertJsonPath('year_month', $yearMonth)
            ->assertJsonPath('status', 'submitted');
    }
}
