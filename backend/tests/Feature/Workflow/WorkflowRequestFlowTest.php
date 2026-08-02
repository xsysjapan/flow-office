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
            'code' => 'business_card',
            'name' => '名刺申請',
            'form_schema' => [['key' => 'amount', 'label' => '金額', 'type' => 'number', 'required' => true]],
            'requires_backoffice_task' => true,
            'backoffice_task_type' => 'business_card',
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

        // 承認依頼の通知には、この申請の詳細画面へのリンクが付く。
        $approverNotifications = $this->actingAs($approver)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith("/requests/{$workflowRequestId}", $approverNotifications[0]['detail_url']);

        $approveResponse = $this->actingAs($approver)
            ->postJson("/api/workflow-requests/{$workflowRequestId}/approve");
        $approveResponse->assertOk()->assertJsonPath('status', 'approved');

        // 承認完了の通知にも同じ申請の詳細画面へのリンクが付く。
        $applicantNotifications = $this->actingAs($applicant)->getJson('/api/notifications/mine')->json('data');
        $this->assertStringEndsWith("/requests/{$workflowRequestId}", $applicantNotifications[0]['detail_url']);

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
        $actions = collect($history->json())->pluck('action');
        $this->assertContains('drafted', $actions);
        $this->assertContains('submitted', $actions);
        $this->assertContains('returned', $actions);

        $returned = collect($history->json())->firstWhere('action', 'returned');
        $this->assertSame('不備があります', $returned['comment']);

        $this->actingAs($approver)->getJson("/api/workflow-requests/{$draft['id']}/history")->assertOk();
        $this->actingAs($stranger)->getJson("/api/workflow-requests/{$draft['id']}/history")->assertForbidden();
    }

    /**
     * @return array{applicant: User, approver: User, requestType: RequestType}
     */
    private function makeApprovableRequestType(): array
    {
        $applicant = User::factory()->create();
        $approver = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'general_request',
            'name' => '一般申請',
            'form_schema' => [],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        return ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType];
    }

    private function createSubmittedRequest(User $applicant, User $approver, RequestType $requestType, string $title = 'テスト申請'): string
    {
        $draft = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => $title,
            'form_data' => [],
            'approver_user_id' => $approver->id,
        ])->json();

        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$draft['id']}/submit");

        return $draft['id'];
    }

    public function test_index_to_approve_defaults_to_submitted_only(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();

        $pendingId = $this->createSubmittedRequest($applicant, $approver, $requestType, '承認待ち申請');
        $approvedId = $this->createSubmittedRequest($applicant, $approver, $requestType, '承認済み申請');
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$approvedId}/approve");

        $response = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($pendingId, $response->json('data.0.id'));
    }

    public function test_index_to_approve_filters_by_status(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();

        $this->createSubmittedRequest($applicant, $approver, $requestType, '承認待ち申請');
        $approvedId = $this->createSubmittedRequest($applicant, $approver, $requestType, '承認済み申請');
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$approvedId}/approve");

        $response = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve?status=approved');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($approvedId, $response->json('data.0.id'));
        $this->assertSame('approved', $response->json('data.0.status'));
    }

    public function test_index_to_approve_status_all_returns_every_status(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();

        $this->createSubmittedRequest($applicant, $approver, $requestType, '承認待ち申請');
        $approvedId = $this->createSubmittedRequest($applicant, $approver, $requestType, '承認済み申請');
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$approvedId}/approve");

        $response = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve?status=all');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_to_approve_filters_by_year_month_of_submitted_at(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();

        $matchingId = $this->createSubmittedRequest($applicant, $approver, $requestType, '対象月の申請');
        \App\Models\WorkflowRequest::query()->whereKey($matchingId)->update(['submitted_at' => '2026-06-15 10:00:00']);

        $otherId = $this->createSubmittedRequest($applicant, $approver, $requestType, '別月の申請');
        \App\Models\WorkflowRequest::query()->whereKey($otherId)->update(['submitted_at' => '2026-07-15 10:00:00']);

        $response = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve?status=all&year_month=2026-06');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($matchingId, $response->json('data.0.id'));
    }

    public function test_index_to_approve_paginates_with_per_page(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();

        for ($i = 0; $i < 3; $i++) {
            $this->createSubmittedRequest($applicant, $approver, $requestType, "申請{$i}");
        }

        $page1 = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve?per_page=2&page=1');
        $page1->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(1, $page1->json('meta.current_page'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertSame(3, $page1->json('meta.total'));

        $page2 = $this->actingAs($approver)->getJson('/api/workflow-requests/to-approve?per_page=2&page=2');
        $page2->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, $page2->json('meta.current_page'));
    }

    public function test_index_to_approve_never_returns_requests_where_caller_is_not_approver(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'requestType' => $requestType] = $this->makeApprovableRequestType();
        $stranger = User::factory()->create();

        $this->createSubmittedRequest($applicant, $approver, $requestType, '他人の承認待ち申請');

        $response = $this->actingAs($stranger)->getJson('/api/workflow-requests/to-approve?status=all');

        $response->assertOk()->assertJsonCount(0, 'data');
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

        $this->actingAs($employee)->postJson('/api/admin/request-types', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/admin/request-types', $payload)->assertCreated();
    }
}
