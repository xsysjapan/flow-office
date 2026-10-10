<?php

namespace Tests\Feature\LeaveRequestLink;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\LeaveRequestLink\Projectors\LeaveRequestWorkflowLinkProjector;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Models\LeaveRequestWorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LeaveRequestWorkflowLinkProjector(leave_request_workflow_links)と問い合わせクラス
 * LeaveRequestWorkflowLinks の検証。イベントは集約IDを付けてProjectorへ直接渡す
 * (tests/Unit/Attendance/WorkStyleProjectorTest.php と同じ方式)。
 */
class LeaveRequestWorkflowLinkProjectorTest extends TestCase
{
    use RefreshDatabase;

    private function projector(): LeaveRequestWorkflowLinkProjector
    {
        return app(LeaveRequestWorkflowLinkProjector::class);
    }

    private function drafted(string $workflowRequestId, ?string $subjectType, ?string $subjectId): WorkflowRequestDrafted
    {
        return (new WorkflowRequestDrafted(
            requestTypeId: null,
            requestTypeCode: null,
            applicantUserId: (string) Str::uuid(),
            title: '休暇申請',
            formData: [],
            approverUserId: (string) Str::uuid(),
            subjectType: $subjectType,
            subjectId: $subjectId,
        ))->setAggregateRootUuid($workflowRequestId);
    }

    public function test_drafted_paid_special_compensatory_creates_link_rows(): void
    {
        $cases = [
            ['paid_leave_request', LeaveRequestWorkflowLink::KIND_PAID],
            ['special_leave_request', LeaveRequestWorkflowLink::KIND_SPECIAL],
            ['compensatory_leave_request', LeaveRequestWorkflowLink::KIND_COMPENSATORY],
        ];

        foreach ($cases as [$subjectType, $leaveKind]) {
            $workflowRequestId = (string) Str::uuid();
            $leaveRequestId = (string) Str::uuid();

            $this->projector()->onWorkflowRequestDrafted($this->drafted($workflowRequestId, $subjectType, $leaveRequestId));

            $this->assertDatabaseHas('leave_request_workflow_links', [
                'workflow_request_id' => $workflowRequestId,
                'leave_kind' => $leaveKind,
                'leave_request_id' => $leaveRequestId,
            ]);
        }

        $this->assertSame(3, LeaveRequestWorkflowLink::query()->count());
    }

    public function test_drafted_with_other_subject_type_creates_no_row(): void
    {
        $this->projector()->onWorkflowRequestDrafted($this->drafted((string) Str::uuid(), 'attendance_month', (string) Str::uuid()));
        $this->projector()->onWorkflowRequestDrafted($this->drafted((string) Str::uuid(), 'expense_claim', (string) Str::uuid()));
        $this->projector()->onWorkflowRequestDrafted($this->drafted((string) Str::uuid(), null, null));

        $this->assertSame(0, LeaveRequestWorkflowLink::query()->count());
    }

    public function test_leave_request_shared_events_create_link_rows(): void
    {
        $paidWorkflowId = (string) Str::uuid();
        $paidLeaveRequestId = (string) Str::uuid();
        $this->projector()->onPaidLeaveRequestLifecycleShared(
            (new PaidLeaveRequestLifecycleShared(workflowRequestId: $paidWorkflowId))->setAggregateRootUuid($paidLeaveRequestId),
        );

        $legacyPaidWorkflowId = (string) Str::uuid();
        $legacyPaidLeaveRequestId = (string) Str::uuid();
        $this->projector()->onPaidLeaveRequestShared(
            (new PaidLeaveRequestShared(workflowRequestId: $legacyPaidWorkflowId))->setAggregateRootUuid($legacyPaidLeaveRequestId),
        );

        $specialWorkflowId = (string) Str::uuid();
        $specialLeaveRequestId = (string) Str::uuid();
        $this->projector()->onSpecialLeaveRequestShared(
            (new SpecialLeaveRequestShared(workflowRequestId: $specialWorkflowId))->setAggregateRootUuid($specialLeaveRequestId),
        );

        $compensatoryWorkflowId = (string) Str::uuid();
        $compensatoryLeaveRequestId = (string) Str::uuid();
        $this->projector()->onCompensatoryLeaveRequestShared(
            (new CompensatoryLeaveRequestShared(workflowRequestId: $compensatoryWorkflowId))->setAggregateRootUuid($compensatoryLeaveRequestId),
        );

        $this->assertDatabaseHas('leave_request_workflow_links', [
            'workflow_request_id' => $paidWorkflowId,
            'leave_kind' => LeaveRequestWorkflowLink::KIND_PAID,
            'leave_request_id' => $paidLeaveRequestId,
        ]);
        $this->assertDatabaseHas('leave_request_workflow_links', [
            'workflow_request_id' => $legacyPaidWorkflowId,
            'leave_kind' => LeaveRequestWorkflowLink::KIND_PAID,
            'leave_request_id' => $legacyPaidLeaveRequestId,
        ]);
        $this->assertDatabaseHas('leave_request_workflow_links', [
            'workflow_request_id' => $specialWorkflowId,
            'leave_kind' => LeaveRequestWorkflowLink::KIND_SPECIAL,
            'leave_request_id' => $specialLeaveRequestId,
        ]);
        $this->assertDatabaseHas('leave_request_workflow_links', [
            'workflow_request_id' => $compensatoryWorkflowId,
            'leave_kind' => LeaveRequestWorkflowLink::KIND_COMPENSATORY,
            'leave_request_id' => $compensatoryLeaveRequestId,
        ]);
        $this->assertSame(4, LeaveRequestWorkflowLink::query()->count());
    }

    public function test_reprocessing_same_workflow_does_not_duplicate_rows(): void
    {
        $workflowRequestId = (string) Str::uuid();
        $leaveRequestId = (string) Str::uuid();

        $drafted = $this->drafted($workflowRequestId, 'special_leave_request', $leaveRequestId);
        $shared = (new SpecialLeaveRequestShared(workflowRequestId: $workflowRequestId))->setAggregateRootUuid($leaveRequestId);

        $this->projector()->onWorkflowRequestDrafted($drafted);
        $this->projector()->onWorkflowRequestDrafted($drafted);
        $this->projector()->onSpecialLeaveRequestShared($shared);
        $this->projector()->onSpecialLeaveRequestShared($shared);

        $this->assertSame(1, LeaveRequestWorkflowLink::query()->where('workflow_request_id', $workflowRequestId)->count());
        $this->assertDatabaseHas('leave_request_workflow_links', [
            'workflow_request_id' => $workflowRequestId,
            'leave_kind' => LeaveRequestWorkflowLink::KIND_SPECIAL,
            'leave_request_id' => $leaveRequestId,
        ]);
    }

    public function test_find_returns_leave_link_or_null(): void
    {
        $workflowRequestId = (string) Str::uuid();
        $leaveRequestId = (string) Str::uuid();
        $this->projector()->onWorkflowRequestDrafted($this->drafted($workflowRequestId, 'compensatory_leave_request', $leaveRequestId));

        $links = app(LeaveRequestWorkflowLinks::class);

        $this->assertSame(
            ['leave_kind' => LeaveRequestWorkflowLink::KIND_COMPENSATORY, 'leave_request_id' => $leaveRequestId],
            $links->find($workflowRequestId),
        );
        $this->assertNull($links->find((string) Str::uuid()));
    }

    public function test_workflow_request_id_for_returns_workflow_id_or_null(): void
    {
        $workflowRequestId = (string) Str::uuid();
        $leaveRequestId = (string) Str::uuid();
        $this->projector()->onWorkflowRequestDrafted($this->drafted($workflowRequestId, 'paid_leave_request', $leaveRequestId));

        $links = app(LeaveRequestWorkflowLinks::class);

        $this->assertSame($workflowRequestId, $links->workflowRequestIdFor(LeaveRequestWorkflowLink::KIND_PAID, $leaveRequestId));
        $this->assertNull($links->workflowRequestIdFor(LeaveRequestWorkflowLink::KIND_SPECIAL, $leaveRequestId));
        $this->assertNull($links->workflowRequestIdFor(LeaveRequestWorkflowLink::KIND_PAID, (string) Str::uuid()));
    }
}
