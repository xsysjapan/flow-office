<?php

namespace Tests\Feature\Workflow;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\RejectWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 論点2-2: 汎用「却下」機能。編集・再提出不可の終端状態であること。
 */
class WorkflowRequestRejectTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function makeSubmittedRequest(): array
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

        $draft = $this->bus()->dispatch(new DraftWorkflowRequest(
            requestTypeCode: $requestType->code,
            applicantUserId: $applicant->id,
            title: 'テスト申請',
            formData: [],
            approverUserId: $approver->id,
        ));

        $this->bus()->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $draft->id,
            submittedByUserId: $applicant->id,
            approverUserId: $approver->id,
        ));

        return ['applicant' => $applicant, 'approver' => $approver, 'workflowRequestId' => $draft->id];
    }

    public function test_the_designated_approver_can_reject_a_submitted_request(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $result = $this->bus()->dispatch(new RejectWorkflowRequest(
            workflowRequestId: $id,
            rejectedByUserId: $approver->id,
            reason: '要件を満たしていません',
        ));

        $this->assertSame(WorkflowRequestStatus::REJECTED, $result->status);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame('要件を満たしていません', $result->rejection_reason);
    }

    public function test_a_stranger_cannot_reject(): void
    {
        ['workflowRequestId' => $id] = $this->makeSubmittedRequest();
        $stranger = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new RejectWorkflowRequest($id, $stranger->id, '理由'));
    }

    public function test_a_draft_request_cannot_be_rejected(): void
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

        $draft = $this->bus()->dispatch(new DraftWorkflowRequest(
            requestTypeCode: $requestType->code,
            applicantUserId: $applicant->id,
            title: 'テスト申請',
            formData: [],
            approverUserId: $approver->id,
        ));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new RejectWorkflowRequest($draft->id, $approver->id, '理由'));
    }

    public function test_a_rejected_request_is_a_terminal_state_and_cannot_be_resubmitted_or_cancelled(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new RejectWorkflowRequest($id, $approver->id, '理由'));

        // 却下は取消可能ステータス一覧に含まれない(終端状態)。
        $this->assertNotContains(WorkflowRequestStatus::REJECTED, WorkflowRequestStatus::cancellable());

        // 再提出(submit)もできない。
        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $id,
            submittedByUserId: $applicant->id,
            approverUserId: $approver->id,
        ));
    }

    public function test_an_already_approved_request_cannot_be_rejected(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: $approver->id,
        ));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new RejectWorkflowRequest($id, $approver->id, '理由'));
    }

    public function test_history_records_the_rejection_with_reason(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new RejectWorkflowRequest($id, $approver->id, '却下理由テキスト'));

        $entry = \App\Models\WorkflowRequestHistoryEntry::query()
            ->where('workflow_request_id', $id)
            ->where('action', 'rejected')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('却下理由テキスト', $entry->comment);
        $this->assertSame($approver->id, $entry->actor_user_id);
    }

    public function test_reject_via_http_endpoint(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $response = $this->actingAs($approver)->postJson("/api/workflow-requests/{$id}/reject", [
            'reason' => '不備があります',
        ]);

        $response->assertOk()->assertJsonPath('status', 'rejected');

        $workflowRequest = WorkflowRequest::query()->findOrFail($id);
        $this->assertSame('不備があります', $workflowRequest->rejection_reason);
    }
}
