<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-W001: 申請種別マスタで設定する「申請可能な対象者」「添付必須有無」の検証。
 */
class RequestTypeMasterConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_an_eligible_role_cannot_draft_the_request_type(): void
    {
        $requestType = RequestType::query()->create([
            'code' => 'executive_only', 'name' => '役員専用申請', 'form_schema' => [], 'is_active' => true,
            'eligible_role_codes' => [Role::ADMIN],
        ]);
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '申請', 'form_data' => [], 'approver_user_id' => $approver->id,
        ])->assertStatus(422);
    }

    public function test_a_user_with_an_eligible_role_can_draft_the_request_type(): void
    {
        $requestType = RequestType::query()->create([
            'code' => 'admin_only', 'name' => '管理者専用申請', 'form_schema' => [], 'is_active' => true,
            'eligible_role_codes' => [Role::ADMIN],
        ]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $approver = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => '申請', 'form_data' => [], 'approver_user_id' => $approver->id,
        ])->assertCreated();
    }

    public function test_submitting_without_an_attachment_fails_when_the_request_type_requires_one(): void
    {
        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
            'requires_attachment' => true,
        ]);
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'approver_user_id' => $approver->id, 'status' => 'draft', 'form_data' => [],
        ]);

        $this->actingAs($applicant)
            ->postJson("/api/workflow-requests/{$workflowRequest->id}/submit")
            ->assertStatus(422);
    }

    public function test_submitting_with_an_attachment_succeeds_when_the_request_type_requires_one(): void
    {
        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement', 'name' => '経費精算', 'form_schema' => [], 'is_active' => true,
            'requires_attachment' => true,
        ]);
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id, 'title' => 'タクシー代', 'applicant_user_id' => $applicant->id,
            'approver_user_id' => $approver->id, 'status' => 'draft', 'form_data' => [],
        ]);
        Attachment::query()->create([
            'owner_type' => 'workflow_request', 'owner_id' => $workflowRequest->id, 'uploaded_by' => $applicant->id,
            'file_name' => 'receipt.pdf', 'stored_path' => 'attachments/workflow-requests/1/receipt.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1024,
        ]);

        $this->actingAs($applicant)
            ->postJson("/api/workflow-requests/{$workflowRequest->id}/submit")
            ->assertOk()->assertJsonPath('status', 'submitted');
    }
}
