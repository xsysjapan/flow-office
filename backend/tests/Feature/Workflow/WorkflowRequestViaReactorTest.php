<?php

namespace Tests\Feature\Workflow;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 業務側(休暇申請等)のReactorから発行される `viaReactor=true` のCommandの振る舞いを確認する。
 *
 * - viaReactor=true: 本人・承認者・権限のチェックを行わず、目的の状態なら何もしない(冪等)。
 * - viaReactor=false(利用者の操作): 従来どおり本人チェック・状態不正を例外にする。
 */
class WorkflowRequestViaReactorTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /**
     * @return array{applicant: User, approver: User, workflowRequestId: string}
     */
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

    private function statusOf(string $id): string
    {
        return WorkflowRequest::query()->findOrFail($id)->status;
    }

    public function test_reactor_cancel_succeeds_even_when_the_canceller_is_not_the_applicant(): void
    {
        ['workflowRequestId' => $id] = $this->makeSubmittedRequest();
        $stranger = User::factory()->create();

        $result = $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $stranger->id,
            reason: '業務側の取消',
            viaReactor: true,
            initiatedByUserId: $stranger->id,
        ));

        $this->assertSame(WorkflowRequestStatus::CANCELLED, $result->status);
        $this->assertSame(WorkflowRequestStatus::CANCELLED, $this->statusOf($id));
    }

    public function test_reactor_cancel_does_nothing_when_already_cancelled(): void
    {
        ['applicant' => $applicant, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $applicant->id,
            reason: '一度目の取消',
        ));

        // 例外にならず、状態も変わらない(冪等)。
        $result = $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $applicant->id,
            reason: '二度目の取消',
            viaReactor: true,
        ));

        $this->assertSame(WorkflowRequestStatus::CANCELLED, $result->status);
        $this->assertSame(WorkflowRequestStatus::CANCELLED, $this->statusOf($id));

        // 取消の履歴は1件のまま(二重に取消されない)。
        $count = \App\Models\WorkflowRequestHistoryEntry::query()
            ->where('workflow_request_id', $id)
            ->where('action', 'cancelled')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_reactor_cancel_does_nothing_when_already_approved(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: $approver->id,
        ));

        // 承認済みは取消不可のため、Reactorからの取消でも例外にせず状態を変えない。
        $result = $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $approver->id,
            reason: '業務側の取消',
            viaReactor: true,
        ));

        $this->assertSame(WorkflowRequestStatus::APPROVED, $result->status);
        $this->assertSame(WorkflowRequestStatus::APPROVED, $this->statusOf($id));
    }

    public function test_user_cancel_by_a_stranger_is_still_rejected(): void
    {
        ['workflowRequestId' => $id] = $this->makeSubmittedRequest();
        $stranger = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $stranger->id,
            reason: '理由',
            viaReactor: false,
        ));
    }

    public function test_user_cancel_of_an_already_cancelled_request_is_still_rejected(): void
    {
        ['applicant' => $applicant, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $applicant->id,
            reason: '一度目の取消',
        ));

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $id,
            cancelledByUserId: $applicant->id,
            reason: '二度目の取消',
        ));
    }

    public function test_reactor_approve_succeeds_even_when_the_approver_is_someone_else(): void
    {
        ['workflowRequestId' => $id] = $this->makeSubmittedRequest();
        $otherApprover = User::factory()->create();

        $result = $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: null,
            viaReactor: true,
            initiatedByUserId: $otherApprover->id,
        ));

        $this->assertSame(WorkflowRequestStatus::APPROVED, $result->status);
        $this->assertSame(WorkflowRequestStatus::APPROVED, $this->statusOf($id));

        // 記録する承認者は連鎖の起点の操作者(initiatedByUserId)。
        $entry = \App\Models\WorkflowRequestHistoryEntry::query()
            ->where('workflow_request_id', $id)
            ->where('action', 'approved')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame($otherApprover->id, $entry->actor_user_id);
    }

    public function test_reactor_approve_does_nothing_when_already_approved(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->makeSubmittedRequest();

        $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: $approver->id,
        ));

        $result = $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: null,
            viaReactor: true,
            initiatedByUserId: $approver->id,
        ));

        $this->assertSame(WorkflowRequestStatus::APPROVED, $result->status);
        $this->assertSame(WorkflowRequestStatus::APPROVED, $this->statusOf($id));

        // 承認の履歴は1件のまま(二重に承認されない)。
        $count = \App\Models\WorkflowRequestHistoryEntry::query()
            ->where('workflow_request_id', $id)
            ->where('action', 'approved')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_user_approve_by_a_stranger_is_still_rejected(): void
    {
        ['workflowRequestId' => $id] = $this->makeSubmittedRequest();
        $stranger = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $id,
            approvedByUserId: $stranger->id,
        ));
    }
}
