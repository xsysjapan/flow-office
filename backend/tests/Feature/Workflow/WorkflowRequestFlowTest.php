<?php

namespace Tests\Feature\Workflow;

use App\Models\BackOfficeTask;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * UC-W002〜UC-W005 + UC-B001: 汎用申請の作成から承認、バックオフィスタスク自動生成まで。
 */
class WorkflowRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_submit_approve_creates_backoffice_task(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement',
            'name' => '経費精算',
            'form_schema' => [['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true]],
            'requires_backoffice_task' => true,
            'backoffice_task_type' => 'expense_reimbursement',
            'backoffice_department' => '経理部',
            'is_active' => true,
        ]);

        $draftResponse = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'タクシー代',
            'form_data' => ['amount' => 1200],
            'approver_user_id' => $approver->id,
        ]);
        $draftResponse->assertCreated();
        $workflowRequestId = $draftResponse->json('id');
        $draftResponse->assertJsonPath('status', 'draft');

        $submitResponse = $this->actingAs($applicant)
            ->postJson("/api/workflow-requests/{$workflowRequestId}/submit");
        $submitResponse->assertOk()->assertJsonPath('status', 'submitted');

        $approveResponse = $this->actingAs($approver)
            ->postJson("/api/workflow-requests/{$workflowRequestId}/approve");
        $approveResponse->assertOk()->assertJsonPath('status', 'approved');

        $task = BackOfficeTask::query()->where('source_id', $workflowRequestId)->first();
        $this->assertNotNull($task, 'バックオフィスタスクが自動生成されていること');
        $this->assertSame('not_started', $task->status);
        $this->assertSame('経理部', $task->assigned_department);
    }

    public function test_only_designated_approver_can_approve(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $stranger = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'general_request',
            'name' => '一般申請',
            'form_schema' => [],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $draft = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'テスト申請',
            'form_data' => [],
            'approver_user_id' => $approver->id,
        ])->json();

        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$draft['id']}/submit");

        $response = $this->actingAs($stranger)->postJson("/api/workflow-requests/{$draft['id']}/approve");

        $response->assertStatus(422);
    }

    public function test_show_includes_attachments(): void
    {
        $applicant = User::factory()->create();
        $requestType = RequestType::query()->create([
            'code' => 'general_request',
            'name' => '一般申請',
            'form_schema' => [],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $draft = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'テスト申請',
            'form_data' => [],
        ])->json();

        $file = UploadedFile::fake()->create('receipt.pdf', 10);
        $this->actingAs($applicant)->postJson('/api/attachments', [
            'owner_type' => 'workflow_request',
            'owner_id' => $draft['id'],
            'file' => $file,
        ])->assertCreated();

        $response = $this->actingAs($applicant)->getJson("/api/workflow-requests/{$draft['id']}");
        $response->assertOk();
        $this->assertCount(1, $response->json('attachments'));
        $this->assertSame('receipt.pdf', $response->json('attachments.0.file_name'));
    }

    public function test_history_is_visible_to_applicant_and_approver_but_not_a_stranger(): void
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $stranger = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'general_request',
            'name' => '一般申請',
            'form_schema' => [],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $draft = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'テスト申請',
            'form_data' => [],
            'approver_user_id' => $approver->id,
        ])->json();

        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$draft['id']}/submit");
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$draft['id']}/return", [
            'comment' => '不備があります',
        ]);

        $history = $this->actingAs($applicant)->getJson("/api/workflow-requests/{$draft['id']}/history");
        $history->assertOk();
        $eventTypes = collect($history->json())->pluck('event_type');
        $this->assertContains('workflow_request.drafted', $eventTypes);
        $this->assertContains('workflow_request.submitted', $eventTypes);
        $this->assertContains('workflow_request.returned', $eventTypes);

        $this->actingAs($approver)->getJson("/api/workflow-requests/{$draft['id']}/history")->assertOk();
        $this->actingAs($stranger)->getJson("/api/workflow-requests/{$draft['id']}/history")->assertForbidden();
    }

    public function test_admin_can_manage_request_types_but_others_cannot(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']));
        $employee = User::factory()->create();

        $payload = [
            'code' => 'business_card',
            'name' => '名刺申請',
            'form_schema' => [],
            'requires_backoffice_task' => true,
            'backoffice_task_type' => 'business_card',
        ];

        $this->actingAs($employee)->postJson('/api/request-types', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/request-types', $payload)->assertCreated();
    }
}
