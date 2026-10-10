<?php

namespace Tests\Feature\PaidLeaveRequest;

use App\Domain\LeaveRequestLink\Projectors\LeaveRequestWorkflowLinkProjector;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleMigrated;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Projectors\PaidLeaveRequestProjector;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\LeaveRequestWorkflowLink;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestUsageLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaidLeaveRequestProjector(paid_leave_requests)の検証。イベントは集約IDを付けてProjectorへ直接渡す
 * (tests/Feature/Attendance/AttendanceDayLeaveProjectorTest.php と同じ方式)。
 *
 * 集約IDの意味: 旧`paid_leave.*`・`paid_leave_request.*`は申請ID、`paid_leave_account.usage_*`は利用者ID、
 * `workflow_request.*`はワークフローID。作成時刻(createdAt)は未保存のイベントでは値が無いため、
 * タイムスタンプの検証は「Projectorがイベントのcreated_atを書いているか」の比較に留める。
 */
class PaidLeaveRequestProjectorTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-12';

    private function projector(): PaidLeaveRequestProjector
    {
        return app(PaidLeaveRequestProjector::class);
    }

    /** イベントのクラス名に対応するon〜メソッドへ渡す(Spatieの自動ディスパッチと同じ規則)。 */
    private function apply(object $event): void
    {
        $method = 'on'.class_basename($event);
        $this->projector()->{$method}($event);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    /** @return array{0: string, 1: string} 申請者ID、承認者ID */
    private function users(): array
    {
        return [User::factory()->create()->id, User::factory()->create()->id];
    }

    private function row(string $requestId): ?PaidLeaveRequest
    {
        return PaidLeaveRequest::query()->find($requestId);
    }

    private function assertState(string $requestId, string $status, ?string $source): PaidLeaveRequest
    {
        $row = $this->row($requestId);
        $this->assertNotNull($row, '行が作られていること');
        $this->assertSame($status, $row->status);
        $this->assertSame($source, $row->input_source);

        return $row;
    }

    /** 列の日時がイベントのcreatedAt()と一致すること(nullの場合はnullであること)。 */
    private function assertWrittenAt(?object $event, mixed $actual): void
    {
        $expected = $event?->createdAt()?->format('Y-m-d H:i:s');
        $this->assertSame($expected, $actual?->format('Y-m-d H:i:s'));
    }

    // ---- イベントの組み立て ----

    private function legacyRequested(string $requestId, string $userId, string $approverId, string $leaveType = 'full', ?float $hours = null, float $days = 1.0): object
    {
        return (new PaidLeaveRequested(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $days,
            approverUserId: $approverId,
            reason: '私用',
            requestGroupId: null,
        ))->setAggregateRootUuid($requestId);
    }

    private function legacyApproved(string $requestId, string $approverId): object
    {
        return (new PaidLeaveRequestApproved(approvedByUserId: $approverId))->setAggregateRootUuid($requestId);
    }

    private function legacyReturned(string $requestId, string $approverId): object
    {
        return (new PaidLeaveRequestReturned(returnedByUserId: $approverId, comment: '差戻し'))->setAggregateRootUuid($requestId);
    }

    private function legacyCancelled(string $requestId, string $userId): object
    {
        return (new PaidLeaveRequestCancelled(cancelledByUserId: $userId))->setAggregateRootUuid($requestId);
    }

    private function designated(string $usageId, string $requestId, string $userId, string $approverId, ?string $workflowId = null, string $usageType = 'full', ?float $hours = null): object
    {
        return (new PaidLeaveUsageDesignated(
            usageId: $usageId,
            workflowRequestId: $workflowId,
            attendanceDayId: null,
            usedOn: self::DATE,
            usedDays: $usageType === 'hourly' ? 0.0 : 1.0,
            usageType: $usageType,
            paidLeaveRequestId: $requestId,
            approverUserId: $approverId,
            reason: '私用',
            requestGroupId: null,
            hours: $hours,
        ))->setAggregateRootUuid($userId);
    }

    private function confirmed(string $usageId, string $userId, string $approverId): object
    {
        return (new PaidLeaveUsageConfirmed(usageId: $usageId, confirmedByUserId: $approverId))->setAggregateRootUuid($userId);
    }

    private function usageCancelled(string $usageId, string $userId): object
    {
        return (new PaidLeaveUsageCancelled(usageId: $usageId, cancelledByUserId: $userId, reason: null))->setAggregateRootUuid($userId);
    }

    private function workflowReturned(string $workflowId, string $approverId): object
    {
        return (new WorkflowRequestReturned(returnedByUserId: $approverId, comment: '差戻し'))->setAggregateRootUuid($workflowId);
    }

    /** 有給申請の草稿(subject=有給申請)。対応表(休暇申請文脈のProjector)へ直接渡す。 */
    private function drafted(string $workflowId, string $requestId, string $applicantId, string $approverId, string $subjectType = WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST): void
    {
        $event = (new WorkflowRequestDrafted(
            requestTypeId: null,
            requestTypeCode: null,
            applicantUserId: $applicantId,
            title: self::DATE.' の有給申請',
            formData: [],
            approverUserId: $approverId,
            subjectType: $subjectType,
            subjectId: $requestId,
        ))->setAggregateRootUuid($workflowId);

        app(LeaveRequestWorkflowLinkProjector::class)->onWorkflowRequestDrafted($event);
    }

    private function lifecycleRequested(string $requestId, string $userId, string $approverId, ?string $workflowId = null): object
    {
        return (new PaidLeaveRequestLifecycleRequested(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: $approverId,
            reason: '私用',
            requestGroupId: null,
            workflowRequestId: $workflowId,
        ))->setAggregateRootUuid($requestId);
    }

    private function migrated(string $requestId, string $userId, string $approverId, string $status, ?string $usageId = null): object
    {
        return (new PaidLeaveRequestLifecycleMigrated(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: $approverId,
            reason: '私用',
            requestGroupId: null,
            workflowRequestId: null,
            status: $status,
            usageId: $usageId,
            hasUsage: $usageId !== null,
        ))->setAggregateRootUuid($requestId);
    }

    // ---- 旧系統(旧 paid_leave.*) ----

    public function test_legacy_requested_then_approved_writes_the_same_columns_as_before(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $requested = $this->legacyRequested($requestId, $employee, $approver, 'hourly', 2.0, 0.5);
        $approved = $this->legacyApproved($requestId, $approver);

        $this->apply($requested);
        $this->apply($approved);

        $row = $this->assertState($requestId, 'approved', PaidLeaveRequest::SOURCE_LEGACY_PAID);
        $this->assertSame($employee, $row->user_id);
        $this->assertSame($approver, $row->approver_user_id);
        $this->assertSame('hourly', $row->leave_type);
        $this->assertSame(self::DATE, $row->target_date->toDateString());
        $this->assertSame(2.0, (float) $row->hours);
        $this->assertSame(0.5, (float) $row->requested_days);
        $this->assertSame('私用', $row->reason);
        $this->assertNull($row->request_group_id);
        $this->assertWrittenAt($requested, $row->submitted_at);
        $this->assertWrittenAt($approved, $row->approved_at);
        $this->assertNull($row->returned_at);
        $this->assertNull($row->cancelled_at);
    }

    public function test_legacy_requested_then_returned(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $returned = $this->legacyReturned($requestId, $approver);

        $this->apply($this->legacyRequested($requestId, $employee, $approver));
        $this->apply($returned);

        $row = $this->assertState($requestId, 'returned', PaidLeaveRequest::SOURCE_LEGACY_PAID);
        $this->assertWrittenAt($returned, $row->returned_at);
        $this->assertNull($row->approved_at);
    }

    public function test_legacy_requested_then_cancelled(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $cancelled = $this->legacyCancelled($requestId, $employee);

        $this->apply($this->legacyRequested($requestId, $employee, $approver));
        $this->apply($cancelled);

        $row = $this->assertState($requestId, 'cancelled', PaidLeaveRequest::SOURCE_LEGACY_PAID);
        $this->assertWrittenAt($cancelled, $row->cancelled_at);
    }

    // ---- cutover後の系統(paid_leave_account.usage_* + workflow_request.returned) ----

    public function test_account_designated_then_confirmed(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $designated = $this->designated($usageId, $requestId, $employee, $approver);
        $confirmed = $this->confirmed($usageId, $employee, $approver);

        $this->apply($designated);
        $this->assertState($requestId, 'submitted', PaidLeaveRequest::SOURCE_PAID_ACCOUNT);
        $this->apply($confirmed);

        $row = $this->assertState($requestId, 'approved', PaidLeaveRequest::SOURCE_PAID_ACCOUNT);
        $this->assertSame($employee, $row->user_id);
        $this->assertSame($approver, $row->approver_user_id);
        $this->assertSame('full', $row->leave_type);
        $this->assertSame(1.0, (float) $row->requested_days);
        $this->assertWrittenAt($designated, $row->submitted_at);
        $this->assertWrittenAt($confirmed, $row->approved_at);
        $this->assertSame($requestId, PaidLeaveRequestUsageLink::query()->whereKey($usageId)->value('paid_leave_request_id'));
    }

    public function test_account_designated_then_cancelled(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $cancelled = $this->usageCancelled($usageId, $employee);

        $this->apply($this->designated($usageId, $requestId, $employee, $approver));
        $this->apply($cancelled);

        $row = $this->assertState($requestId, 'cancelled', PaidLeaveRequest::SOURCE_PAID_ACCOUNT);
        $this->assertWrittenAt($cancelled, $row->cancelled_at);
    }

    public function test_account_returned_through_the_workflow_request_link(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $workflowId = $this->uuid();
        $returned = $this->workflowReturned($workflowId, $approver);

        $this->drafted($workflowId, $requestId, $employee, $approver);
        $this->apply($this->designated($usageId, $requestId, $employee, $approver, $workflowId));
        $this->apply($returned);

        $row = $this->assertState($requestId, 'returned', PaidLeaveRequest::SOURCE_PAID_ACCOUNT);
        $this->assertWrittenAt($returned, $row->returned_at);
    }

    public function test_workflow_returned_without_a_paid_leave_link_changes_nothing(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $otherWorkflowId = $this->uuid();
        $otherSubjectWorkflowId = $this->uuid();

        // 対応表が無いワークフロー(有給の草稿が無い)と、有給以外のsubjectのワークフローは有給申請を変えない。
        $this->drafted($otherSubjectWorkflowId, $this->uuid(), $employee, $approver, 'expense_claim');
        $this->apply($this->designated($usageId, $requestId, $employee, $approver));

        $this->apply($this->workflowReturned($otherWorkflowId, $approver));
        $this->apply($this->workflowReturned($otherSubjectWorkflowId, $approver));

        $this->assertState($requestId, 'submitted', PaidLeaveRequest::SOURCE_PAID_ACCOUNT);
        $this->assertSame(0, LeaveRequestWorkflowLink::query()->where('leave_kind', LeaveRequestWorkflowLink::KIND_PAID)->where('workflow_request_id', $otherWorkflowId)->count());
    }

    public function test_designated_without_a_paid_leave_request_id_creates_no_row(): void
    {
        [$employee, $approver] = $this->users();
        $usageId = $this->uuid();

        $event = (new PaidLeaveUsageDesignated(
            usageId: $usageId,
            workflowRequestId: null,
            attendanceDayId: null,
            usedOn: self::DATE,
            usedDays: 1.0,
            usageType: 'full',
            paidLeaveRequestId: null,
        ))->setAggregateRootUuid($employee);

        $this->apply($event);
        $this->apply($this->confirmed($usageId, $employee, $approver));

        $this->assertSame(0, PaidLeaveRequest::query()->count());
        $this->assertSame(0, PaidLeaveRequestUsageLink::query()->count());
    }

    // ---- 新系統(paid_leave_request.*) ----

    public function test_lifecycle_requested_then_approved(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $requested = $this->lifecycleRequested($requestId, $employee, $approver);
        $approved = (new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver))->setAggregateRootUuid($requestId);

        $this->apply($requested);
        $this->apply($approved);

        $row = $this->assertState($requestId, 'approved', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertSame($employee, $row->user_id);
        $this->assertSame($approver, $row->approver_user_id);
        $this->assertWrittenAt($requested, $row->submitted_at);
        $this->assertWrittenAt($approved, $row->approved_at);
    }

    public function test_lifecycle_returned_then_resubmitted_keeps_the_returned_time(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $returned = (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $approver, comment: '日程を再検討'))->setAggregateRootUuid($requestId);
        $resubmitted = (new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $employee))->setAggregateRootUuid($requestId);

        $this->apply($this->lifecycleRequested($requestId, $employee, $approver));
        $this->apply($returned);
        $this->assertState($requestId, 'returned', PaidLeaveRequest::SOURCE_PAID_REQUEST);

        $this->apply($resubmitted);

        $row = $this->assertState($requestId, 'submitted', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertWrittenAt($resubmitted, $row->submitted_at);
        $this->assertWrittenAt($returned, $row->returned_at);
    }

    public function test_lifecycle_approved_then_cancelled(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $cancelled = (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $employee, reason: '予定変更'))->setAggregateRootUuid($requestId);

        $this->apply($this->lifecycleRequested($requestId, $employee, $approver));
        $this->apply((new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver))->setAggregateRootUuid($requestId));
        $this->apply($cancelled);

        $row = $this->assertState($requestId, 'cancelled', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertWrittenAt($cancelled, $row->cancelled_at);
    }

    public function test_lifecycle_transition_before_its_request_creates_nothing(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();

        $this->apply((new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver))->setAggregateRootUuid($requestId));

        $this->assertNull($this->row($requestId));
    }

    // ---- 引き継ぎ(paid_leave_request.migrated) ----

    public function test_migrated_writes_each_status_with_its_own_timestamp(): void
    {
        [$employee, $approver] = $this->users();

        foreach (['submitted', 'returned', 'approved', 'cancelled'] as $status) {
            $requestId = $this->uuid();
            $migrated = $this->migrated($requestId, $employee, $approver, $status);
            $this->apply($migrated);

            $row = $this->assertState($requestId, $status, PaidLeaveRequest::SOURCE_PAID_REQUEST);
            $this->assertWrittenAt($migrated, $row->submitted_at);
            $this->assertWrittenAt($status === 'approved' ? $migrated : null, $row->approved_at);
            $this->assertWrittenAt($status === 'returned' ? $migrated : null, $row->returned_at);
            $this->assertWrittenAt($status === 'cancelled' ? $migrated : null, $row->cancelled_at);
        }
    }

    public function test_migrated_takes_over_a_row_created_by_the_legacy_system(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();

        $this->apply($this->legacyRequested($requestId, $employee, $approver));
        $this->apply($this->legacyApproved($requestId, $approver));
        $this->apply($this->migrated($requestId, $employee, $approver, 'returned'));

        $row = $this->assertState($requestId, 'returned', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertNull($row->approved_at);
    }

    public function test_migrated_uses_the_original_timestamps_when_given(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $event = (new PaidLeaveRequestLifecycleMigrated(
            userId: $employee,
            targetDate: self::DATE,
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: $approver,
            reason: null,
            requestGroupId: null,
            workflowRequestId: null,
            status: 'approved',
            usageId: null,
            hasUsage: false,
            submittedAt: '2026-08-01 09:00:00',
            approvedAt: '2026-08-03 10:30:00',
        ))->setAggregateRootUuid($requestId);

        $this->apply($event);

        $row = $this->assertState($requestId, 'approved', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertSame('2026-08-01 09:00:00', $row->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 10:30:00', $row->approved_at->format('Y-m-d H:i:s'));
        $this->assertNull($row->returned_at);
        $this->assertNull($row->cancelled_at);
    }

    public function test_requested_without_approver_uses_the_applicant_as_placeholder(): void
    {
        [$employee] = $this->users();
        $requestId = $this->uuid();

        $this->apply((new PaidLeaveRequestLifecycleRequested(
            userId: $employee,
            targetDate: self::DATE,
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: null,
            reason: null,
        ))->setAggregateRootUuid($requestId));

        $row = $this->assertState($requestId, 'submitted', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertSame($employee, $row->approver_user_id);
    }

    // ---- 系統の切り替え ----

    public function test_after_migrated_legacy_and_account_events_do_not_change_the_row(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $workflowId = $this->uuid();

        // 引き継ぎ前にcutover後の系統で申請された状態(消化記録・対応表あり)。
        $this->drafted($workflowId, $requestId, $employee, $approver);
        $this->apply($this->designated($usageId, $requestId, $employee, $approver, $workflowId));
        $this->apply($this->migrated($requestId, $employee, $approver, 'returned'));

        // 引き継ぎ以後に来た旧系統・cutover後の系統のイベントは無視される。
        $this->apply($this->confirmed($usageId, $employee, $approver));
        $this->apply($this->workflowReturned($workflowId, $approver));
        $this->apply($this->usageCancelled($usageId, $employee));
        $this->apply($this->legacyApproved($requestId, $approver));
        $this->apply($this->legacyCancelled($requestId, $employee));

        $row = $this->assertState($requestId, 'returned', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->cancelled_at);
    }

    public function test_lifecycle_row_ignores_later_account_and_legacy_events(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();

        $this->apply($this->lifecycleRequested($requestId, $employee, $approver));
        // 新系統で作られた申請には、後から旧系統・cutover後の系統のイベントが来ても行を作らず変えない。
        $this->apply($this->designated($usageId, $requestId, $employee, $approver));
        $this->apply($this->confirmed($usageId, $employee, $approver));
        $this->apply($this->legacyCancelled($requestId, $employee));

        $this->assertState($requestId, 'submitted', PaidLeaveRequest::SOURCE_PAID_REQUEST);
        $this->assertSame(0, PaidLeaveRequestUsageLink::query()->count(), '新系統の行には消化記録の対応を作らない');
    }

    // ---- 冪等性・リビルド ----

    /** @return array<string, array<string, mixed>> */
    private function snapshot(): array
    {
        return PaidLeaveRequest::query()->orderBy('id')->get()->mapWithKeys(fn (PaidLeaveRequest $r) => [
            $r->id => $r->only(['input_source', 'user_id', 'approver_user_id', 'status', 'leave_type', 'target_date', 'hours', 'requested_days', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at']),
        ])->all();
    }

    public function test_applying_the_same_events_twice_keeps_the_same_state_and_rebuild_reproduces_it(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $usageId = $this->uuid();
        $workflowId = $this->uuid();
        $lifecycleId = $this->uuid();

        $events = [
            fn () => $this->drafted($workflowId, $requestId, $employee, $approver),
            fn () => $this->apply($this->designated($usageId, $requestId, $employee, $approver, $workflowId)),
            fn () => $this->apply($this->workflowReturned($workflowId, $approver)),
            fn () => $this->apply($this->lifecycleRequested($lifecycleId, $employee, $approver)),
            fn () => $this->apply((new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver))->setAggregateRootUuid($lifecycleId)),
        ];

        foreach ($events as $apply) {
            $apply();
        }
        $once = $this->snapshot();

        foreach ($events as $apply) {
            $apply();
        }
        $this->assertEquals($once, $this->snapshot(), '同じイベント列の再適用で状態が変わらない');

        // 空から再生成しても同じ状態になる(リビルド)。
        DB::table('paid_leave_request_usage_links')->delete();
        DB::table('leave_request_workflow_links')->delete();
        PaidLeaveRequest::query()->delete();
        foreach ($events as $apply) {
            $apply();
        }
        $this->assertEquals($once, $this->snapshot(), '空から再生成しても同じ状態になる');
    }
}
