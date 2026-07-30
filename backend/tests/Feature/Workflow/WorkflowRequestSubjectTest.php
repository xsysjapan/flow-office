<?php

namespace Tests\Feature\Workflow;

use App\Models\EntityShare;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * workflow_requestsを月次勤怠申請・経費精算申請の横断一覧・詳細表示にも使えるようにする
 * 拡張(subject_type/subject_id)のテスト。
 * WorkflowRequestSubjectProjectorがAttendanceMonth/ExpenseClaimの提出/承認/差戻し
 * イベントを購読してworkflow_requestsへ行をupsertすること、show()がsubject_typeに応じて
 * 詳細情報を出し分けること、共有されていない第三者は403になることを確認する。
 */
class WorkflowRequestSubjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_monthly_attendance_creates_a_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'attendance_month')
            ->first();

        $this->assertNotNull($workflowRequest, '月次勤怠の提出でworkflow_requestsに行が作成されること');
        $this->assertSame($employee->id, $workflowRequest->applicant_user_id);
        $this->assertSame($approver->id, $workflowRequest->approver_user_id);
        $this->assertSame('submitted', $workflowRequest->status);
        $this->assertNull($workflowRequest->request_type_id);

        $monthId = $workflowRequest->subject_id;

        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")->assertOk();

        $workflowRequest->refresh();
        $this->assertSame('approved', $workflowRequest->status);
        $this->assertNotNull($workflowRequest->approved_at);
    }

    public function test_returning_a_monthly_attendance_updates_the_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'attendance_month')->firstOrFail();

        $this->actingAs($approver)->postJson("/api/attendance-months/{$workflowRequest->subject_id}/return", [
            'comment' => '不備があります',
        ])->assertOk();

        $workflowRequest->refresh();
        $this->assertSame('returned', $workflowRequest->status);
        $this->assertNotNull($workflowRequest->returned_at);
    }

    public function test_submitting_an_expense_claim_creates_a_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'description' => '自宅 → 会社(電車)',
            'amount' => 500, 'usage_date' => '2026-07-01',
        ])->assertCreated();

        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'expense_claim')
            ->where('subject_id', $claimId)
            ->first();

        $this->assertNotNull($workflowRequest, '経費精算の提出でworkflow_requestsに行が作成されること');
        $this->assertSame($employee->id, $workflowRequest->applicant_user_id);
        $this->assertSame($approver->id, $workflowRequest->approver_user_id);
        $this->assertSame('submitted', $workflowRequest->status);
        $this->assertNull($workflowRequest->request_type_id);

        $this->actingAs($approver)->postJson("/api/expense-claims/{$claimId}/approve")->assertOk();

        $workflowRequest->refresh();
        $this->assertSame('approved', $workflowRequest->status);
    }

    public function test_show_returns_attendance_month_days_and_breaks_for_the_applicant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'attendance_month')->firstOrFail();

        $response = $this->actingAs($employee)->getJson("/api/workflow-requests/{$workflowRequest->id}");
        $response->assertOk();
        $this->assertSame('attendance_month', $response->json('subject_type'));
        $this->assertSame('attendance_month', $response->json('subject.type'));
        $this->assertSame($yearMonth, $response->json('subject.year_month'));
        $this->assertNotEmpty($response->json('subject.days'));

        // 承認者(共有先)も同じ詳細を閲覧できる。
        $this->actingAs($approver)->getJson("/api/workflow-requests/{$workflowRequest->id}")->assertOk();
    }

    public function test_show_returns_expense_claim_items_for_the_applicant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'description' => '自宅 → 会社(電車)',
            'amount' => 500, 'usage_date' => '2026-07-01',
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'expense_claim')
            ->where('subject_id', $claimId)
            ->firstOrFail();

        $response = $this->actingAs($employee)->getJson("/api/workflow-requests/{$workflowRequest->id}");
        $response->assertOk();
        $this->assertSame('expense_claim', $response->json('subject_type'));
        $this->assertSame('expense_claim', $response->json('subject.type'));
        $this->assertCount(1, $response->json('subject.items'));
        $this->assertSame(500, $response->json('subject.items.0.amount'));
    }

    public function test_a_third_party_without_a_share_gets_403(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $stranger = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $workflowRequest = WorkflowRequest::query()->where('subject_type', 'attendance_month')->firstOrFail();

        $this->actingAs($stranger)->getJson("/api/workflow-requests/{$workflowRequest->id}")->assertForbidden();
    }

    public function test_a_user_shared_via_entity_share_can_view_the_subject_detail(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $auditor = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);

        $claimId = $this->actingAs($employee)->postJson('/api/expense-claims')->json('id');
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'description' => '自宅 → 会社(電車)',
            'amount' => 500, 'usage_date' => '2026-07-01',
        ])->assertCreated();
        $this->actingAs($employee)->postJson("/api/expense-claims/{$claimId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'expense_claim')
            ->where('subject_id', $claimId)
            ->firstOrFail();

        // 承認者・申請者以外は本来403だが、entity_sharesに自分宛の共有があれば閲覧できる。
        $this->actingAs($auditor)->getJson("/api/workflow-requests/{$workflowRequest->id}")->assertForbidden();

        EntityShare::query()->create([
            'shareable_type' => 'expense_claim',
            'shareable_id' => $claimId,
            'shared_with_user_id' => $auditor->id,
            'shared_by_user_id' => $employee->id,
            'shared_at' => now(),
        ]);

        $this->actingAs($auditor)->getJson("/api/workflow-requests/{$workflowRequest->id}")->assertOk();
    }

    public function test_normal_workflow_requests_are_unaffected(): void
    {
        $applicant = User::factory()->create();

        $requestType = \App\Models\RequestType::query()->create([
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

        $response = $this->actingAs($applicant)->getJson("/api/workflow-requests/{$draft['id']}");
        $response->assertOk();
        $this->assertNull($response->json('subject_type'));
        $this->assertArrayNotHasKey('subject', $response->json());
    }
}
