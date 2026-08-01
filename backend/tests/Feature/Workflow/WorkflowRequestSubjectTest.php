<?php

namespace Tests\Feature\Workflow;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Jobs\SendNotificationJob;
use App\Models\EntityShare;
use App\Models\ExpenseCategory;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * workflow_requestsを月次勤怠申請・経費精算申請の申請本体としても使えるようにする
 * 拡張(subject_type/subject_id)のテスト。
 *
 * subject付きの申請はDraftWorkflowRequestにsubject_type/subject_idを渡して作成し、
 * 以降の提出・承認・差戻しは汎用申請とまったく同じCommand/Handler(および
 * /api/workflow-requests/* エンドポイント)を通る。ここでは
 * - Draft経由でsubject_type/subject_idがworkflow_requestsへ反映されること
 * - 申請種別マスタを持たない(request_type_idがnull)状態でも提出・承認・差戻しできること
 * - subject_typeに応じた通知文言が送られること
 * - show()がsubject_typeに応じて詳細情報を出し分け、共有されていない第三者は403になること
 * を確認する。
 *
 * 後続フェーズでAttendanceMonth/ExpenseClaim側のReactorがこのDraft呼び出しを担うため、
 * ここではテストコードから直接Draftコマンドを発行している。
 */
class WorkflowRequestSubjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 月次勤怠を提出し、そのattendance_monthを対象とするworkflow_requestを下書き作成する。
     *
     * @return array{0: WorkflowRequest, 1: string}
     */
    private function draftAttendanceMonthRequest(User $employee, User $approver, string $yearMonth): array
    {
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $monthId = $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful()->json('id');

        $request = app(CommandBus::class)->dispatch(new DraftWorkflowRequest(
            requestTypeCode: null,
            applicantUserId: $employee->id,
            title: "{$yearMonth} 月次勤怠",
            formData: [],
            approverUserId: $approver->id,
            subjectType: 'attendance_month',
            subjectId: $monthId,
        ));

        return [$request, $monthId];
    }

    /**
     * 経費精算を作成・提出し、そのexpense_claimを対象とするworkflow_requestを下書き作成する。
     *
     * @return array{0: WorkflowRequest, 1: string}
     */
    private function draftExpenseClaimRequest(User $employee, User $approver): array
    {
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

        $request = app(CommandBus::class)->dispatch(new DraftWorkflowRequest(
            requestTypeCode: null,
            applicantUserId: $employee->id,
            title: '経費精算申請',
            formData: [],
            approverUserId: $approver->id,
            subjectType: 'expense_claim',
            subjectId: $claimId,
        ));

        return [$request, $claimId];
    }

    public function test_drafting_with_a_subject_creates_a_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        [$request, $monthId] = $this->draftAttendanceMonthRequest($employee, $approver, $yearMonth);

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'attendance_month')
            ->firstOrFail();

        $this->assertSame($request->id, $workflowRequest->id);
        $this->assertSame($monthId, $workflowRequest->subject_id);
        $this->assertSame($employee->id, $workflowRequest->applicant_user_id);
        $this->assertSame($approver->id, $workflowRequest->approver_user_id);
        $this->assertSame('draft', $workflowRequest->status);
        $this->assertNull($workflowRequest->request_type_id);

        // 申請種別マスタを持たない行でも、汎用申請と同じ提出・承認フローを通れる。
        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequest->id}/submit")->assertOk();
        $workflowRequest->refresh();
        $this->assertSame('submitted', $workflowRequest->status);

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$workflowRequest->id}/approve")->assertOk();
        $workflowRequest->refresh();
        $this->assertSame('approved', $workflowRequest->status);
        $this->assertNotNull($workflowRequest->approved_at);
    }

    public function test_returning_a_monthly_attendance_request_updates_the_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        [$request] = $this->draftAttendanceMonthRequest($employee, $approver, $yearMonth);

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$request->id}/submit")->assertOk();
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$request->id}/return", [
            'comment' => '不備があります',
        ])->assertOk();

        $request->refresh();
        $this->assertSame('returned', $request->status);
        $this->assertNotNull($request->returned_at);
    }

    public function test_drafting_an_expense_claim_subject_creates_a_workflow_request_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        [$request, $claimId] = $this->draftExpenseClaimRequest($employee, $approver);

        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', 'expense_claim')
            ->where('subject_id', $claimId)
            ->firstOrFail();

        $this->assertSame($request->id, $workflowRequest->id);
        $this->assertSame($employee->id, $workflowRequest->applicant_user_id);
        $this->assertSame($approver->id, $workflowRequest->approver_user_id);
        $this->assertNull($workflowRequest->request_type_id);

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$workflowRequest->id}/submit")->assertOk();
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$workflowRequest->id}/approve")->assertOk();

        $workflowRequest->refresh();
        $this->assertSame('approved', $workflowRequest->status);
    }

    public function test_notifications_use_subject_specific_wording(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        [$request] = $this->draftAttendanceMonthRequest($employee, $approver, $yearMonth);

        Queue::fake();

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$request->id}/submit")->assertOk();
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->title === '月次勤怠の承認依頼'
                && $job->summary === "{$yearMonth} の月次勤怠が提出されました。"
                && str_ends_with((string) $job->detailUrl, '/attendance/months/to-approve'),
        );

        $this->actingAs($approver)->postJson("/api/workflow-requests/{$request->id}/return", [
            'comment' => '不備があります',
        ])->assertOk();
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->title === '月次勤怠が差戻されました'
                && $job->summary === "{$yearMonth} の月次勤怠が差し戻されました: 不備があります",
        );

        $this->actingAs($employee)->postJson("/api/workflow-requests/{$request->id}/submit")->assertOk();
        $this->actingAs($approver)->postJson("/api/workflow-requests/{$request->id}/approve")->assertOk();
        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->title === '月次勤怠が承認されました'
                && $job->summary === "{$yearMonth} の月次勤怠が承認されました。バックオフィス確認対象になります。"
                && str_ends_with((string) $job->detailUrl, "/attendance/months/{$yearMonth}"),
        );
    }

    public function test_generic_requests_keep_their_original_notification_wording(): void
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

        $draftId = $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'テスト申請',
            'form_data' => [],
        ])->json('id');

        Queue::fake();

        $this->actingAs($applicant)->postJson("/api/workflow-requests/{$draftId}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertOk();

        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->title === '承認依頼'
                && $job->summary === '「テスト申請」の承認依頼が届いています。',
        );
    }

    public function test_show_returns_attendance_month_days_and_breaks_for_the_applicant(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $yearMonth = Carbon::today($employee->timezone)->format('Y-m');

        [$workflowRequest] = $this->draftAttendanceMonthRequest($employee, $approver, $yearMonth);

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

        [$workflowRequest] = $this->draftExpenseClaimRequest($employee, $approver);

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

        [$workflowRequest] = $this->draftAttendanceMonthRequest($employee, $approver, $yearMonth);

        $this->actingAs($stranger)->getJson("/api/workflow-requests/{$workflowRequest->id}")->assertForbidden();
    }

    public function test_a_user_shared_via_entity_share_can_view_the_subject_detail(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $auditor = User::factory()->create();

        [$workflowRequest, $claimId] = $this->draftExpenseClaimRequest($employee, $approver);

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

        $response = $this->actingAs($applicant)->getJson("/api/workflow-requests/{$draft['id']}");
        $response->assertOk();
        $this->assertNull($response->json('subject_type'));
        $this->assertArrayNotHasKey('subject', $response->json());
    }
}
