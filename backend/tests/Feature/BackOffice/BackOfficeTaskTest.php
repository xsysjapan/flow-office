<?php

namespace Tests\Feature\BackOffice;

use App\Models\BackOfficeTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-B002/UC-B003: 担当者割当・処理ステータス更新。承認とは別ステータス系列であること。
 */
class BackOfficeTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_and_change_status(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        $task = BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => 1,
            'task_type' => 'business_card',
            'title' => 'テストタスク',
            'status' => 'not_started',
        ]);

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $staff->id,
        ])->assertOk()->assertJsonPath('status', 'in_review');

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'payment_scheduled',
        ])->assertOk()->assertJsonPath('status', 'payment_scheduled');

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('backoffice_tasks', ['id' => $task->id, 'status' => 'completed']);
    }

    public function test_employee_without_backoffice_role_cannot_access(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/backoffice-tasks/unassigned')->assertForbidden();
    }

    /**
     * 月次勤怠確認タスク(attendance_month_confirmation)は人事部(hr_staff)に割り当てるため、
     * hr_staffロールもバックオフィスタスクAPIにアクセスできる必要がある。
     */
    public function test_hr_staff_can_access_backoffice_tasks(): void
    {
        $hrStaff = User::factory()->create();
        $hrStaff->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        $task = BackOfficeTask::query()->create([
            'source_type' => 'attendance_month',
            'source_id' => 1,
            'task_type' => 'attendance_month_confirmation',
            'title' => '月次勤怠確認: テスト社員 (2026-06)',
            'status' => 'not_started',
        ]);

        $this->actingAs($hrStaff)->getJson('/api/backoffice-tasks/unassigned')->assertOk();

        $this->actingAs($hrStaff)->postJson("/api/backoffice-tasks/{$task->id}/assign", [
            'assigned_user_id' => $hrStaff->id,
        ])->assertOk()->assertJsonPath('status', 'in_review');
    }
}
