<?php

namespace Tests\Unit\RequestCenter;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\ExpenseClaim\Events\ExpenseClaimApproved;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDeleted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimDrafted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimSubmitted;
use App\Domain\ExpenseClaim\Events\ExpenseClaimTitleUpdated;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\RequestCenter\Projectors\RequestCenterItemProjector;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\ExpenseClaimStatus;
use App\Models\PaidLeaveRequestStatus;
use App\Models\RequestCenterItem;
use App\Models\RequestCenterItemType;
use App\Models\User;
use App\Models\WorkflowRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RequestCenterItemProjectorが冪等であること・4ドメインそれぞれのイベントから
 * request_center_itemsを正しく作成・更新することを確認する
 * (.claude/skills/add-projection チェックリスト対応)。
 */
class RequestCenterItemProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_leave_requested_creates_row_and_cancelled_updates_status(): void
    {
        $projector = app(RequestCenterItemProjector::class);
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $requestId = (string) Str::uuid();

        $requested = new PaidLeaveRequested(
            userId: $requester->id,
            targetDate: '2026-09-01',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: $approver->id,
            reason: null,
        );
        $requested->setAggregateRootUuid($requestId);
        $projector->onPaidLeaveRequested($requested);

        $item = RequestCenterItem::query()->findOrFail($requestId);
        $this->assertSame(RequestCenterItemType::PAID_LEAVE, $item->request_type);
        $this->assertSame($requestId, $item->source_id);
        $this->assertSame(PaidLeaveRequestStatus::SUBMITTED, $item->status);
        $this->assertSame($requester->id, $item->requester_id);
        $this->assertSame($approver->id, $item->approver_id);

        $cancelled = new PaidLeaveRequestCancelled(cancelledByUserId: $requester->id);
        $cancelled->setAggregateRootUuid($requestId);
        $projector->onPaidLeaveRequestCancelled($cancelled);

        $this->assertSame(PaidLeaveRequestStatus::CANCELLED, $item->refresh()->status);

        // 再適用しても結果が変わらない(冪等性)。
        $projector->onPaidLeaveRequested($requested);
        $projector->onPaidLeaveRequestCancelled($cancelled);
        $this->assertSame(1, RequestCenterItem::query()->count());
        $this->assertSame(PaidLeaveRequestStatus::CANCELLED, $item->refresh()->status);
    }

    public function test_compensatory_leave_requested_creates_row_and_approved_updates_status(): void
    {
        $projector = app(RequestCenterItemProjector::class);
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $requestId = (string) Str::uuid();

        $requested = new CompensatoryLeaveRequested(
            userId: $requester->id,
            targetDate: '2026-09-02',
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            requestedMinutes: null,
            approverUserId: $approver->id,
            reason: null,
        );
        $requested->setAggregateRootUuid($requestId);
        $projector->onCompensatoryLeaveRequested($requested);

        $item = RequestCenterItem::query()->findOrFail($requestId);
        $this->assertSame(RequestCenterItemType::COMPENSATORY_LEAVE, $item->request_type);
        $this->assertSame(CompensatoryLeaveRequestStatus::SUBMITTED, $item->status);

        $approved = new CompensatoryLeaveRequestApproved(approvedByUserId: $approver->id);
        $approved->setAggregateRootUuid($requestId);
        $projector->onCompensatoryLeaveRequestApproved($approved);

        $this->assertSame(CompensatoryLeaveRequestStatus::APPROVED, $item->refresh()->status);
    }

    public function test_expense_claim_lifecycle_updates_title_and_status_and_delete_removes_row(): void
    {
        $projector = app(RequestCenterItemProjector::class);
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $claimId = (string) Str::uuid();

        $drafted = new ExpenseClaimDrafted(employeeId: $requester->id);
        $drafted->setAggregateRootUuid($claimId);
        $projector->onExpenseClaimDrafted($drafted);

        $item = RequestCenterItem::query()->findOrFail($claimId);
        $this->assertSame(ExpenseClaimStatus::DRAFT, $item->status);
        $this->assertNull($item->approver_id);

        $titleUpdated = new ExpenseClaimTitleUpdated(title: '出張旅費');
        $titleUpdated->setAggregateRootUuid($claimId);
        $projector->onExpenseClaimTitleUpdated($titleUpdated);
        $this->assertSame('出張旅費', $item->refresh()->title);

        $submitted = new ExpenseClaimSubmitted(approverUserId: $approver->id, submittedByUserId: $requester->id);
        $submitted->setAggregateRootUuid($claimId);
        $submitted->setCreatedAt(\Carbon\CarbonImmutable::now());
        $projector->onExpenseClaimSubmitted($submitted);
        $item->refresh();
        $this->assertSame(ExpenseClaimStatus::IN_REVIEW, $item->status);
        $this->assertSame($approver->id, $item->approver_id);
        $this->assertNotNull($item->submitted_at);

        $approvedEvent = new ExpenseClaimApproved(approvedByUserId: $approver->id);
        $approvedEvent->setAggregateRootUuid($claimId);
        $projector->onExpenseClaimApproved($approvedEvent);
        $this->assertSame(ExpenseClaimStatus::APPROVED, $item->refresh()->status);

        $deleted = new ExpenseClaimDeleted(deletedByUserId: $requester->id);
        $deleted->setAggregateRootUuid($claimId);
        $projector->onExpenseClaimDeleted($deleted);
        $this->assertNull(RequestCenterItem::query()->find($claimId));
    }

    public function test_workflow_request_drafted_creates_row_and_approved_updates_status(): void
    {
        $projector = app(RequestCenterItemProjector::class);
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $requestId = (string) Str::uuid();

        $drafted = new WorkflowRequestDrafted(
            requestTypeId: null,
            requestTypeCode: null,
            applicantUserId: $applicant->id,
            title: '名刺申請',
            formData: [],
            approverUserId: $approver->id,
        );
        $drafted->setAggregateRootUuid($requestId);
        $projector->onWorkflowRequestDrafted($drafted);

        $item = RequestCenterItem::query()->findOrFail($requestId);
        $this->assertSame(RequestCenterItemType::WORKFLOW, $item->request_type);
        $this->assertSame(WorkflowRequestStatus::DRAFT, $item->status);
        $this->assertSame('名刺申請', $item->title);

        $submitted = new WorkflowRequestSubmitted(approverUserId: $approver->id, submittedByUserId: $applicant->id);
        $submitted->setAggregateRootUuid($requestId);
        $projector->onWorkflowRequestSubmitted($submitted);
        $this->assertSame(WorkflowRequestStatus::SUBMITTED, $item->refresh()->status);

        $approved = new WorkflowRequestApproved(approvedByUserId: $approver->id);
        $approved->setAggregateRootUuid($requestId);
        $projector->onWorkflowRequestApproved($approved);
        $this->assertSame(WorkflowRequestStatus::APPROVED, $item->refresh()->status);
    }
}
