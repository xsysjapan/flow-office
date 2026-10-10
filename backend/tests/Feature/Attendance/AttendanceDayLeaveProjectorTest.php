<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Projectors\AttendanceDayLeaveProjector;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
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
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\Workflow\Events\WorkflowRequestReturned;
use App\Models\AttendanceDayLeave;
use App\Models\AttendanceDayLeavePaidUsage;
use App\Models\PaidLeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AttendanceDayLeaveProjector(attendance_day_leaves)と問い合わせクラス AttendanceDayLeaves の検証。
 * イベントは集約IDを付けてProjectorへ直接渡す(tests/Unit/Attendance/WorkStyleProjectorTest.php と同じ方式)。
 * 集約IDの意味: 有給・特別・代休の申請イベントは申請ID、有給の消化記録(paid_leave_account.*)は利用者ID、
 * ワークフローのイベントはワークフローID。
 */
class AttendanceDayLeaveProjectorTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-12';

    private function projector(): AttendanceDayLeaveProjector
    {
        return app(AttendanceDayLeaveProjector::class);
    }

    private function row(string $leaveKind, string $leaveRequestId): ?AttendanceDayLeave
    {
        return AttendanceDayLeave::query()
            ->where('leave_kind', $leaveKind)
            ->where('leave_request_id', $leaveRequestId)
            ->first();
    }

    private function assertStatus(string $leaveKind, string $leaveRequestId, string $status): void
    {
        $this->assertSame($status, $this->row($leaveKind, $leaveRequestId)?->request_status);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    // ---- 有給・旧系統(旧 paid_leave.*) ----

    private function legacyRequested(string $requestId, string $userId, string $leaveType = PaidLeaveType::FULL, ?float $hours = null): void
    {
        $this->projector()->onPaidLeaveRequested((new PaidLeaveRequested(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($requestId));
    }

    public function test_legacy_paid_request_approved(): void
    {
        $requestId = $this->uuid();
        $this->legacyRequested($requestId, $this->uuid());
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);

        $this->projector()->onPaidLeaveRequestApproved((new PaidLeaveRequestApproved(approvedByUserId: $this->uuid()))
            ->setAggregateRootUuid($requestId));

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_APPROVED);
        $this->assertSame(AttendanceDayLeave::SOURCE_LEGACY_PAID, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->source);
    }

    public function test_legacy_paid_request_returned(): void
    {
        $requestId = $this->uuid();
        $this->legacyRequested($requestId, $this->uuid());

        $this->projector()->onPaidLeaveRequestReturned((new PaidLeaveRequestReturned(
            returnedByUserId: $this->uuid(),
            comment: '差戻し',
        ))->setAggregateRootUuid($requestId));

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_RETURNED);
        $this->assertSame(1, AttendanceDayLeave::query()->count(), '差戻しでも行は削除しない');
    }

    public function test_legacy_paid_request_cancelled_and_shared(): void
    {
        $requestId = $this->uuid();
        $workflowId = $this->uuid();
        $this->legacyRequested($requestId, $this->uuid());

        $this->projector()->onPaidLeaveRequestShared((new PaidLeaveRequestShared(workflowRequestId: $workflowId))
            ->setAggregateRootUuid($requestId));
        $this->assertSame($workflowId, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->workflow_request_id);

        $this->projector()->onPaidLeaveRequestCancelled((new PaidLeaveRequestCancelled(cancelledByUserId: $this->uuid()))
            ->setAggregateRootUuid($requestId));
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);
    }

    // ---- 有給・cutover後の系統(paid_leave_account.usage_*) ----

    private function designate(string $usageId, string $requestId, string $userId, string $workflowId, string $usageType = PaidLeaveType::FULL, ?float $hours = null): void
    {
        $this->projector()->onPaidLeaveUsageDesignated((new PaidLeaveUsageDesignated(
            usageId: $usageId,
            workflowRequestId: $workflowId,
            attendanceDayId: null,
            usedOn: self::DATE,
            usedDays: 1.0,
            usageType: $usageType,
            paidLeaveRequestId: $requestId,
            approverUserId: null,
            reason: null,
            requestGroupId: null,
            hours: $hours,
        ))->setAggregateRootUuid($userId));
    }

    public function test_paid_account_usage_designated_then_confirmed(): void
    {
        $usageId = $this->uuid();
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $this->designate($usageId, $requestId, $userId, $this->uuid());

        $row = $this->row(AttendanceDayLeave::KIND_PAID, $requestId);
        $this->assertSame(AttendanceDayLeave::SOURCE_PAID_ACCOUNT, $row->source);
        $this->assertSame(AttendanceDayLeave::STATUS_SUBMITTED, $row->request_status);
        $this->assertSame($userId, $row->user_id);
        $this->assertSame(self::DATE, $row->work_date);
        $this->assertSame(PaidLeaveType::FULL, $row->unit);
        $this->assertSame(
            $requestId,
            AttendanceDayLeavePaidUsage::query()->whereKey($usageId)->value('leave_request_id'),
        );

        $this->projector()->onPaidLeaveUsageConfirmed(
            (new PaidLeaveUsageConfirmed(usageId: $usageId, confirmedByUserId: $this->uuid()))->setAggregateRootUuid($userId),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_APPROVED);
    }

    public function test_paid_account_usage_designated_then_cancelled(): void
    {
        $usageId = $this->uuid();
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $this->designate($usageId, $requestId, $userId, $this->uuid());

        $this->projector()->onPaidLeaveUsageCancelled(
            (new PaidLeaveUsageCancelled(usageId: $usageId, cancelledByUserId: $this->uuid(), reason: null))
                ->setAggregateRootUuid($userId),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);
    }

    public function test_paid_account_workflow_returned_marks_matching_rows_returned(): void
    {
        $usageId = $this->uuid();
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $workflowId = $this->uuid();
        $this->designate($usageId, $requestId, $userId, $workflowId);

        $this->projector()->onWorkflowRequestReturned(
            (new WorkflowRequestReturned(returnedByUserId: $this->uuid(), comment: '差戻し'))->setAggregateRootUuid($workflowId),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_RETURNED);
        $this->assertSame(1, AttendanceDayLeave::query()->count(), '差戻しでも行は削除しない');
    }

    public function test_workflow_returned_does_not_touch_other_workflows(): void
    {
        $requestId = $this->uuid();
        $this->designate($this->uuid(), $requestId, $this->uuid(), $this->uuid());

        $this->projector()->onWorkflowRequestReturned(
            (new WorkflowRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($this->uuid()),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);
    }

    public function test_cancelled_paid_account_row_stays_cancelled_when_workflow_returned(): void
    {
        $usageId = $this->uuid();
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $workflowId = $this->uuid();
        $this->designate($usageId, $requestId, $userId, $workflowId);

        $this->projector()->onPaidLeaveUsageCancelled(
            (new PaidLeaveUsageCancelled(usageId: $usageId, cancelledByUserId: $this->uuid(), reason: null))
                ->setAggregateRootUuid($userId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);

        $this->projector()->onWorkflowRequestReturned(
            (new WorkflowRequestReturned(returnedByUserId: $this->uuid(), comment: '差戻し'))->setAggregateRootUuid($workflowId),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);
    }

    // ---- 有給・新系統(paid_leave_request.*) ----

    private function lifecycleRequested(string $requestId, string $userId, string $leaveType = PaidLeaveType::FULL, ?float $hours = null, ?string $workflowId = null): void
    {
        $this->projector()->onPaidLeaveRequestLifecycleRequested((new PaidLeaveRequestLifecycleRequested(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
            requestGroupId: null,
            workflowRequestId: $workflowId,
        ))->setAggregateRootUuid($requestId));
    }

    public function test_paid_request_lifecycle_approved(): void
    {
        $requestId = $this->uuid();
        $this->lifecycleRequested($requestId, $this->uuid());
        $this->assertSame(AttendanceDayLeave::SOURCE_PAID_REQUEST, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->source);

        $this->projector()->onPaidLeaveRequestLifecycleApproved(
            (new PaidLeaveRequestLifecycleApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
        );

        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_APPROVED);
    }

    public function test_paid_request_lifecycle_returned_then_resubmitted_then_cancelled(): void
    {
        $requestId = $this->uuid();
        $workflowId = $this->uuid();
        $this->lifecycleRequested($requestId, $this->uuid());
        $this->projector()->onPaidLeaveRequestLifecycleShared(
            (new PaidLeaveRequestLifecycleShared(workflowRequestId: $workflowId))->setAggregateRootUuid($requestId),
        );

        $this->projector()->onPaidLeaveRequestLifecycleReturned(
            (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $this->uuid(), comment: '差戻し'))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_RETURNED);

        $this->projector()->onPaidLeaveRequestLifecycleResubmitted(
            (new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);
        $this->assertSame($workflowId, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->workflow_request_id);

        $this->projector()->onPaidLeaveRequestLifecycleCancelled(
            (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $this->uuid(), reason: null))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);
    }

    public function test_paid_request_migrated_takes_over_with_given_status(): void
    {
        $requestId = $this->uuid();
        $workflowId = $this->uuid();
        $this->projector()->onPaidLeaveRequestLifecycleMigrated((new PaidLeaveRequestLifecycleMigrated(
            userId: $this->uuid(),
            targetDate: self::DATE,
            leaveType: PaidLeaveType::PM_HALF,
            hours: null,
            requestedDays: 0.5,
            approverUserId: null,
            reason: null,
            requestGroupId: null,
            workflowRequestId: $workflowId,
            status: AttendanceDayLeave::STATUS_RETURNED,
            usageId: null,
            hasUsage: false,
        ))->setAggregateRootUuid($requestId));

        $row = $this->row(AttendanceDayLeave::KIND_PAID, $requestId);
        $this->assertSame(AttendanceDayLeave::SOURCE_PAID_REQUEST, $row->source);
        $this->assertSame(AttendanceDayLeave::STATUS_RETURNED, $row->request_status);
        $this->assertSame(PaidLeaveType::PM_HALF, $row->unit);
        $this->assertSame($workflowId, $row->workflow_request_id);
    }

    /**
     * 系統の切り替え(論点4・13の境界条件): 新系統(migrated)になった申請には、旧系統・cutover後の系統の
     * イベントを適用しない。
     */
    public function test_old_system_events_are_ignored_after_paid_request_takeover(): void
    {
        $usageId = $this->uuid();
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $workflowId = $this->uuid();

        // 移行前: cutover後の系統で申請中(usage_designated)
        $this->designate($usageId, $requestId, $userId, $workflowId);

        // 移行: 承認済みとして引き継ぐ
        $this->projector()->onPaidLeaveRequestLifecycleMigrated((new PaidLeaveRequestLifecycleMigrated(
            userId: $userId,
            targetDate: self::DATE,
            leaveType: PaidLeaveType::FULL,
            hours: null,
            requestedDays: 1.0,
            approverUserId: null,
            reason: null,
            requestGroupId: null,
            workflowRequestId: $workflowId,
            status: AttendanceDayLeave::STATUS_APPROVED,
            usageId: $usageId,
            hasUsage: true,
        ))->setAggregateRootUuid($requestId));

        // 移行後に旧系統のイベントが来ても変わらない
        $this->projector()->onPaidLeaveUsageCancelled(
            (new PaidLeaveUsageCancelled(usageId: $usageId, cancelledByUserId: $this->uuid(), reason: null))->setAggregateRootUuid($userId),
        );
        $this->projector()->onWorkflowRequestReturned(
            (new WorkflowRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($workflowId),
        );
        $this->projector()->onPaidLeaveRequestReturned(
            (new PaidLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($requestId),
        );

        $row = $this->row(AttendanceDayLeave::KIND_PAID, $requestId);
        $this->assertSame(AttendanceDayLeave::SOURCE_PAID_REQUEST, $row->source);
        $this->assertSame(AttendanceDayLeave::STATUS_APPROVED, $row->request_status);
        $this->assertSame(1, AttendanceDayLeave::query()->count());
    }

    public function test_legacy_request_created_later_does_not_override_paid_request_row(): void
    {
        $requestId = $this->uuid();
        $this->lifecycleRequested($requestId, $this->uuid());

        // 同じ申請IDの旧系統の作成イベントは、既存行が別の系統なので適用しない
        $this->legacyRequested($requestId, $this->uuid());

        $row = $this->row(AttendanceDayLeave::KIND_PAID, $requestId);
        $this->assertSame(AttendanceDayLeave::SOURCE_PAID_REQUEST, $row->source);
        $this->assertSame(AttendanceDayLeave::STATUS_SUBMITTED, $row->request_status);
    }

    // ---- 特別休暇・代休(3種 × 申請→承認/差戻し/取消) ----

    public function test_special_leave_request_approved_returned_and_cancelled(): void
    {
        $userId = $this->uuid();
        foreach (['approved', 'returned', 'cancelled'] as $flow) {
            $requestId = $this->uuid();
            $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
                userId: $userId,
                specialLeaveTypeId: 3,
                targetDate: self::DATE,
                leaveType: PaidLeaveType::AM_HALF,
                hours: null,
                requestedDays: 0.5,
                approverUserId: $this->uuid(),
                reason: null,
            ))->setAggregateRootUuid($requestId));
            $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);
            $this->assertSame(3, $this->row(AttendanceDayLeave::KIND_SPECIAL, $requestId)->special_leave_type_id);

            match ($flow) {
                'approved' => $this->projector()->onSpecialLeaveRequestApproved(
                    (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
                'returned' => $this->projector()->onSpecialLeaveRequestReturned(
                    (new SpecialLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($requestId),
                ),
                'cancelled' => $this->projector()->onSpecialLeaveRequestCancelled(
                    (new SpecialLeaveRequestCancelled(cancelledByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
            };

            $expected = match ($flow) {
                'approved' => AttendanceDayLeave::STATUS_APPROVED,
                'returned' => AttendanceDayLeave::STATUS_RETURNED,
                'cancelled' => AttendanceDayLeave::STATUS_CANCELLED,
            };
            $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $requestId, $expected);
            $this->assertSame(AttendanceDayLeave::SOURCE_SPECIAL, $this->row(AttendanceDayLeave::KIND_SPECIAL, $requestId)->source);
        }
    }

    public function test_compensatory_leave_request_approved_returned_and_cancelled(): void
    {
        $userId = $this->uuid();
        foreach (['approved', 'returned', 'cancelled'] as $flow) {
            $requestId = $this->uuid();
            $this->projector()->onCompensatoryLeaveRequested((new CompensatoryLeaveRequested(
                userId: $userId,
                targetDate: self::DATE,
                leaveType: PaidLeaveType::FULL,
                hours: null,
                requestedDays: 1.0,
                requestedMinutes: null,
                approverUserId: $this->uuid(),
                reason: null,
            ))->setAggregateRootUuid($requestId));
            $this->assertStatus(AttendanceDayLeave::KIND_COMPENSATORY, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);

            match ($flow) {
                'approved' => $this->projector()->onCompensatoryLeaveRequestApproved(
                    (new CompensatoryLeaveRequestApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
                'returned' => $this->projector()->onCompensatoryLeaveRequestReturned(
                    (new CompensatoryLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($requestId),
                ),
                'cancelled' => $this->projector()->onCompensatoryLeaveRequestCancelled(
                    (new CompensatoryLeaveRequestCancelled(cancelledByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
            };

            $expected = match ($flow) {
                'approved' => AttendanceDayLeave::STATUS_APPROVED,
                'returned' => AttendanceDayLeave::STATUS_RETURNED,
                'cancelled' => AttendanceDayLeave::STATUS_CANCELLED,
            };
            $this->assertStatus(AttendanceDayLeave::KIND_COMPENSATORY, $requestId, $expected);
            $this->assertSame(AttendanceDayLeave::SOURCE_COMPENSATORY, $this->row(AttendanceDayLeave::KIND_COMPENSATORY, $requestId)->source);
        }
    }

    // ---- 時間休の分数 ----

    public function test_minutes_for_hourly_leaves(): void
    {
        // 特別休暇: round(hours*60)
        $specialId = $this->uuid();
        $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
            userId: $this->uuid(),
            specialLeaveTypeId: 1,
            targetDate: self::DATE,
            leaveType: PaidLeaveType::HOURLY,
            hours: 1.5,
            requestedDays: 0.1875,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($specialId));
        $this->assertSame(90, $this->row(AttendanceDayLeave::KIND_SPECIAL, $specialId)->minutes);

        // 代休: requestedMinutes があればそれを優先する(hours*60=90 ではなく 100)
        $compensatoryId = $this->uuid();
        $this->projector()->onCompensatoryLeaveRequested((new CompensatoryLeaveRequested(
            userId: $this->uuid(),
            targetDate: self::DATE,
            leaveType: PaidLeaveType::HOURLY,
            hours: 1.5,
            requestedDays: 0.2,
            requestedMinutes: 100,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($compensatoryId));
        $this->assertSame(100, $this->row(AttendanceDayLeave::KIND_COMPENSATORY, $compensatoryId)->minutes);

        // 代休で requestedMinutes が無ければ round(hours*60)
        $compensatoryNoMinutesId = $this->uuid();
        $this->projector()->onCompensatoryLeaveRequested((new CompensatoryLeaveRequested(
            userId: $this->uuid(),
            targetDate: self::DATE,
            leaveType: PaidLeaveType::HOURLY,
            hours: 0.25,
            requestedDays: 0.05,
            requestedMinutes: null,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($compensatoryNoMinutesId));
        $this->assertSame(15, $this->row(AttendanceDayLeave::KIND_COMPENSATORY, $compensatoryNoMinutesId)->minutes);

        // 有給(cutover後の系統): 時間休は消化記録のhoursからround(hours*60)
        $usageId = $this->uuid();
        $paidRequestId = $this->uuid();
        $this->designate($usageId, $paidRequestId, $this->uuid(), $this->uuid(), PaidLeaveType::HOURLY, 2.25);
        $this->assertSame(135, $this->row(AttendanceDayLeave::KIND_PAID, $paidRequestId)->minutes);
        $this->assertSame(2.25, $this->row(AttendanceDayLeave::KIND_PAID, $paidRequestId)->hours);

        // 時間休以外は分数null
        $fullId = $this->uuid();
        $this->legacyRequested($fullId, $this->uuid(), PaidLeaveType::FULL);
        $this->assertNull($this->row(AttendanceDayLeave::KIND_PAID, $fullId)->minutes);
    }

    // ---- 冪等性 ----

    public function test_reapplying_the_same_events_gives_the_same_state(): void
    {
        $requestId = $this->uuid();
        $userId = $this->uuid();
        $usageId = $this->uuid();
        $paidAccountRequestId = $this->uuid();
        $workflowId = $this->uuid();

        for ($i = 0; $i < 2; $i++) {
            $this->legacyRequested($requestId, $userId, PaidLeaveType::AM_HALF);
            $this->projector()->onPaidLeaveRequestApproved(
                (new PaidLeaveRequestApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
            );
            $this->designate($usageId, $paidAccountRequestId, $userId, $workflowId, PaidLeaveType::PM_HALF, null);
            $this->projector()->onPaidLeaveUsageConfirmed(
                (new PaidLeaveUsageConfirmed(usageId: $usageId, confirmedByUserId: $this->uuid()))->setAggregateRootUuid($userId),
            );
        }

        $this->assertSame(2, AttendanceDayLeave::query()->count());
        $this->assertSame(1, AttendanceDayLeavePaidUsage::query()->count());
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_APPROVED);
        $this->assertSame(PaidLeaveType::AM_HALF, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->unit);
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $paidAccountRequestId, AttendanceDayLeave::STATUS_APPROVED);
    }

    public function test_events_without_a_created_row_do_nothing(): void
    {
        $requestId = $this->uuid();
        $this->projector()->onSpecialLeaveRequestApproved(
            (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($requestId),
        );

        $this->assertSame(0, AttendanceDayLeave::query()->count());
    }

    // ---- 問い合わせ(有効な休暇だけ返す) ----

    public function test_query_returns_only_submitted_and_approved_leaves(): void
    {
        $userId = $this->uuid();
        $otherUserId = $this->uuid();
        $targetDate = self::DATE;
        $laterDate = '2026-10-17';

        // 有効: 特別休暇(承認済み)
        $specialApprovedId = $this->uuid();
        $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: 2,
            targetDate: $targetDate,
            leaveType: PaidLeaveType::FULL,
            hours: null,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($specialApprovedId));
        $this->projector()->onSpecialLeaveRequestApproved(
            (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid()))->setAggregateRootUuid($specialApprovedId),
        );

        // 有効: 有給(新系統・申請中)
        $paidSubmittedId = $this->uuid();
        $this->lifecycleRequested($paidSubmittedId, $userId, PaidLeaveType::AM_HALF);

        // 無効: 代休(差戻し)・有給(取消)
        $compensatoryReturnedId = $this->uuid();
        $this->projector()->onCompensatoryLeaveRequested((new CompensatoryLeaveRequested(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: PaidLeaveType::PM_HALF,
            hours: null,
            requestedDays: 0.5,
            requestedMinutes: null,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($compensatoryReturnedId));
        $this->projector()->onCompensatoryLeaveRequestReturned(
            (new CompensatoryLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: ''))->setAggregateRootUuid($compensatoryReturnedId),
        );

        $paidCancelledId = $this->uuid();
        $this->lifecycleRequested($paidCancelledId, $userId);
        $this->projector()->onPaidLeaveRequestLifecycleCancelled(
            (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $this->uuid(), reason: null))->setAggregateRootUuid($paidCancelledId),
        );

        // 別日・別利用者(有効だが対象外)
        $laterSpecialId = $this->uuid();
        $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: 2,
            targetDate: $laterDate,
            leaveType: PaidLeaveType::FULL,
            hours: null,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($laterSpecialId));
        $this->lifecycleRequested($this->uuid(), $otherUserId);

        $query = app(AttendanceDayLeaves::class);

        $active = $query->activeFor($userId, $targetDate);
        $this->assertCount(2, $active);
        $this->assertSame([
            [AttendanceDayLeave::KIND_PAID, $paidSubmittedId, AttendanceDayLeave::STATUS_SUBMITTED],
            [AttendanceDayLeave::KIND_SPECIAL, $specialApprovedId, AttendanceDayLeave::STATUS_APPROVED],
        ], array_map(fn (array $leave): array => [
            $leave['leave_kind'],
            $leave['leave_request_id'],
            $leave['request_status'],
        ], $active));
        $this->assertSame(PaidLeaveType::AM_HALF, $active[0]['unit']);
        $this->assertSame(2, $active[1]['special_leave_type_id']);

        $range = $query->activeForRange($userId, $targetDate, $laterDate);
        $this->assertCount(3, $range);
        $this->assertSame([$targetDate, $targetDate, $laterDate], array_column($range, 'work_date'));
    }
}
