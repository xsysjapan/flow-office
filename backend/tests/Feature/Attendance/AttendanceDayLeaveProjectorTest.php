<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Projectors\AttendanceDayLeaveProjector;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Models\AttendanceDayLeave;
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

    // ---- 有給(paid_leave_request.*) ----

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

        $this->projector()->onPaidLeaveRequestLifecycleApproved(
            (new PaidLeaveRequestLifecycleApproved(approvedByUserId: $this->uuid(), userId: $this->uuid()))->setAggregateRootUuid($requestId),
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
            (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $this->uuid(), comment: '差戻し', userId: $this->uuid()))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_RETURNED);

        $this->projector()->onPaidLeaveRequestLifecycleResubmitted(
            (new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);
        $this->assertSame($workflowId, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->workflow_request_id);

        $this->projector()->onPaidLeaveRequestLifecycleCancelled(
            (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $this->uuid(), reason: null, userId: $this->uuid()))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_PAID, $requestId, AttendanceDayLeave::STATUS_CANCELLED);
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
                    (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE, requiresGrant: true))->setAggregateRootUuid($requestId),
                ),
                'returned' => $this->projector()->onSpecialLeaveRequestReturned(
                    (new SpecialLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: '', userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($requestId),
                ),
                'cancelled' => $this->projector()->onSpecialLeaveRequestCancelled(
                    (new SpecialLeaveRequestCancelled(cancelledByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($requestId),
                ),
            };

            $expected = match ($flow) {
                'approved' => AttendanceDayLeave::STATUS_APPROVED,
                'returned' => AttendanceDayLeave::STATUS_RETURNED,
                'cancelled' => AttendanceDayLeave::STATUS_CANCELLED,
            };
            $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $requestId, $expected);
        }
    }

    public function test_special_leave_request_returned_then_resubmitted_restores_only_the_returned_row(): void
    {
        $userId = $this->uuid();
        $requestId = $this->uuid();
        $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: 3,
            targetDate: self::DATE,
            leaveType: PaidLeaveType::FULL,
            hours: null,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($requestId));

        $this->projector()->onSpecialLeaveRequestReturned(
            (new SpecialLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: '差戻し', userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $requestId, AttendanceDayLeave::STATUS_RETURNED);

        $this->projector()->onSpecialLeaveRequestResubmitted(
            (new SpecialLeaveRequestResubmitted(resubmittedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($requestId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $requestId, AttendanceDayLeave::STATUS_SUBMITTED);

        // 差戻し中でない行(承認済み)は再提出の通知で変わらない。
        $approvedId = $this->uuid();
        $this->projector()->onSpecialLeaveRequested((new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: 3,
            targetDate: self::DATE,
            leaveType: PaidLeaveType::FULL,
            hours: null,
            requestedDays: 1.0,
            approverUserId: $this->uuid(),
            reason: null,
        ))->setAggregateRootUuid($approvedId));
        $this->projector()->onSpecialLeaveRequestApproved(
            (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE, requiresGrant: true))->setAggregateRootUuid($approvedId),
        );
        $this->projector()->onSpecialLeaveRequestResubmitted(
            (new SpecialLeaveRequestResubmitted(resubmittedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE))->setAggregateRootUuid($approvedId),
        );
        $this->assertStatus(AttendanceDayLeave::KIND_SPECIAL, $approvedId, AttendanceDayLeave::STATUS_APPROVED);
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
                    (new CompensatoryLeaveRequestApproved(approvedByUserId: $this->uuid(), userId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
                'returned' => $this->projector()->onCompensatoryLeaveRequestReturned(
                    (new CompensatoryLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: '', userId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
                'cancelled' => $this->projector()->onCompensatoryLeaveRequestCancelled(
                    (new CompensatoryLeaveRequestCancelled(cancelledByUserId: $this->uuid(), userId: $this->uuid()))->setAggregateRootUuid($requestId),
                ),
            };

            $expected = match ($flow) {
                'approved' => AttendanceDayLeave::STATUS_APPROVED,
                'returned' => AttendanceDayLeave::STATUS_RETURNED,
                'cancelled' => AttendanceDayLeave::STATUS_CANCELLED,
            };
            $this->assertStatus(AttendanceDayLeave::KIND_COMPENSATORY, $requestId, $expected);
        }
    }

    // ---- 時間休の分数 ----

    // ---- 冪等性 ----

    public function test_events_without_a_created_row_do_nothing(): void
    {
        $requestId = $this->uuid();
        $this->projector()->onSpecialLeaveRequestApproved(
            (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE, requiresGrant: true))->setAggregateRootUuid($requestId),
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
            (new SpecialLeaveRequestApproved(approvedByUserId: $this->uuid(), userId: $this->uuid(), targetDate: self::DATE, requiresGrant: true))->setAggregateRootUuid($specialApprovedId),
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
            (new CompensatoryLeaveRequestReturned(returnedByUserId: $this->uuid(), comment: '', userId: $this->uuid()))->setAggregateRootUuid($compensatoryReturnedId),
        );

        $paidCancelledId = $this->uuid();
        $this->lifecycleRequested($paidCancelledId, $userId);
        $this->projector()->onPaidLeaveRequestLifecycleCancelled(
            (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $this->uuid(), reason: null, userId: $this->uuid()))->setAggregateRootUuid($paidCancelledId),
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

    public function test_minutes_for_hourly_leaves(): void
    {
        $requestId = $this->uuid();
        $this->lifecycleRequested($requestId, $this->uuid(), PaidLeaveType::HOURLY, 0.5);

        $this->assertSame(30, $this->row(AttendanceDayLeave::KIND_PAID, $requestId)->minutes);
    }

    public function test_reapplying_the_same_events_gives_the_same_state(): void
    {
        $userId = $this->uuid();
        $requestId = $this->uuid();
        $this->lifecycleRequested($requestId, $userId);
        $returned = (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $this->uuid(), comment: '差戻し', userId: $userId))
            ->setAggregateRootUuid($requestId);

        $this->projector()->onPaidLeaveRequestLifecycleReturned($returned);
        $first = AttendanceDayLeave::query()->where('leave_request_id', $requestId)->get()->map->getAttributes()->all();

        $this->projector()->onPaidLeaveRequestLifecycleReturned($returned);
        $this->assertSame($first, AttendanceDayLeave::query()->where('leave_request_id', $requestId)->get()->map->getAttributes()->all());
    }
}
