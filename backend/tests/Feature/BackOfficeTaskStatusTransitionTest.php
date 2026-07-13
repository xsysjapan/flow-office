<?php

namespace Tests\Feature;

use App\Models\BackOfficeTask;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-B003: タスク種別(task_type)ごとに処理フロー・ステータス遷移が異なるため、
 * 遷移の許可は申請種別マスタ(request_types.allowed_status_transitions)で定義する。
 */
class BackOfficeTaskStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaff(): User
    {
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));

        return $staff;
    }

    private function makeTaskWithTransitions(?array $transitions, string $initialStatus = 'not_started'): BackOfficeTask
    {
        $applicant = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
            'requires_backoffice_task' => true, 'backoffice_task_type' => 'expense_reimbursement',
            'allowed_status_transitions' => $transitions,
        ]);
        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'status' => 'approved', 'form_data' => ['amount' => 1000],
        ]);

        return BackOfficeTask::query()->create([
            'source_type' => 'workflow_request', 'source_id' => $workflowRequest->id,
            'task_type' => 'expense_reimbursement', 'title' => '経費精算: タクシー代', 'status' => $initialStatus,
        ]);
    }

    public function test_rejects_a_transition_not_in_the_request_types_allowed_list(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions([
            'not_started' => ['in_review'],
            'in_review' => ['payment_scheduled'],
        ]);

        // not_started -> payment_scheduled をスキップするのは許可されていない。
        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'payment_scheduled',
        ])->assertStatus(422);

        $this->assertSame('not_started', $task->refresh()->status);
    }

    public function test_allows_a_transition_in_the_request_types_allowed_list(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions([
            'not_started' => ['in_review'],
            'in_review' => ['payment_scheduled'],
        ]);

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'in_review',
        ])->assertOk()->assertJsonPath('status', 'in_review');
    }

    public function test_allows_any_transition_when_request_type_has_no_configured_transitions(): void
    {
        $staff = $this->makeStaff();
        $task = $this->makeTaskWithTransitions(null);

        $this->actingAs($staff)->postJson("/api/backoffice-tasks/{$task->id}/status", [
            'status' => 'payment_scheduled',
        ])->assertOk()->assertJsonPath('status', 'payment_scheduled');
    }
}
