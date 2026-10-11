<?php

namespace Tests\Feature\PaidLeaveRequest;

use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Domain\PaidLeaveRequest\Projectors\PaidLeaveRequestProjector;
use App\Models\PaidLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaidLeaveRequestProjector(paid_leave_requests)の検証。入力は`paid_leave_request.*`だけ。
 * イベントは集約ID(=有給申請ID)を付けてProjectorへ直接渡す(tests/Feature/Attendance/AttendanceDayLeaveProjectorTest.php と同じ方式)。
 *
 * 作成時刻(createdAt)は未保存のイベントでは値が無いため、タイムスタンプの検証は
 * 「Projectorがイベントのcreated_atを書いているか」の比較に留める。
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

    /** @return array{0: string, 1: string} 申請者と承認者 */
    private function users(): array
    {
        return [User::factory()->create()->id, User::factory()->create()->id];
    }

    private function row(string $requestId): ?PaidLeaveRequest
    {
        return PaidLeaveRequest::query()->find($requestId);
    }

    private function assertState(string $requestId, string $status): PaidLeaveRequest
    {
        $row = $this->row($requestId);
        $this->assertNotNull($row, '行が作られていること');
        $this->assertSame($status, $row->status);

        return $row;
    }

    /** 列の日時がイベントのcreatedAt()と一致すること(nullの場合はnullであること)。 */
    private function assertWrittenAt(?object $event, mixed $actual): void
    {
        $expected = $event?->createdAt()?->format('Y-m-d H:i:s');
        $this->assertSame($expected, $actual?->format('Y-m-d H:i:s'));
    }

    private function requested(string $requestId, string $userId, ?string $approverId, ?string $workflowId = null): PaidLeaveRequestLifecycleRequested
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

    public function test_requested_creates_a_submitted_row_with_the_request_details(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $requested = $this->requested($requestId, $employee, $approver, 'wf-1');

        $this->apply($requested);

        $row = $this->assertState($requestId, 'submitted');
        $this->assertSame($employee, $row->user_id);
        $this->assertSame($approver, $row->approver_user_id);
        $this->assertSame(self::DATE, $row->target_date->toDateString());
        $this->assertSame('full', $row->leave_type);
        $this->assertSame(1.0, (float) $row->requested_days);
        $this->assertSame('私用', $row->reason);
        $this->assertWrittenAt($requested, $row->submitted_at);
        $this->assertNull($row->approved_at);
    }

    public function test_requested_without_approver_uses_the_applicant_as_placeholder(): void
    {
        [$employee] = $this->users();
        $requestId = $this->uuid();

        $this->apply($this->requested($requestId, $employee, null));

        $this->assertSame($employee, $this->assertState($requestId, 'submitted')->approver_user_id);
    }

    public function test_requested_then_approved_sets_the_approval_time(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $requested = $this->requested($requestId, $employee, $approver);
        $approved = (new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver, userId: $employee))->setAggregateRootUuid($requestId);

        $this->apply($requested);
        $this->apply($approved);

        $row = $this->assertState($requestId, 'approved');
        $this->assertWrittenAt($approved, $row->approved_at);
    }

    public function test_returned_then_resubmitted_keeps_the_returned_time(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $returned = (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $approver, comment: '日程を再検討', userId: $employee))
            ->setAggregateRootUuid($requestId);
        $resubmitted = (new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $employee, userId: $employee, targetDate: self::DATE))
            ->setAggregateRootUuid($requestId);

        $this->apply($this->requested($requestId, $employee, $approver));
        $this->apply($returned);
        $this->assertState($requestId, 'returned');

        $this->apply($resubmitted);

        $row = $this->assertState($requestId, 'submitted');
        $this->assertWrittenAt($resubmitted, $row->submitted_at);
        $this->assertWrittenAt($returned, $row->returned_at);
    }

    public function test_approved_then_cancelled_sets_the_cancellation_time(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $cancelled = (new PaidLeaveRequestLifecycleCancelled(cancelledByUserId: $employee, reason: '予定変更', userId: $employee))
            ->setAggregateRootUuid($requestId);

        $this->apply($this->requested($requestId, $employee, $approver));
        $this->apply((new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver, userId: $employee))->setAggregateRootUuid($requestId));
        $this->apply($cancelled);

        $row = $this->assertState($requestId, 'cancelled');
        $this->assertWrittenAt($cancelled, $row->cancelled_at);
    }

    public function test_transition_before_its_request_creates_nothing(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();

        $this->apply((new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver, userId: $employee))->setAggregateRootUuid($requestId));

        $this->assertNull($this->row($requestId));
    }

    public function test_applying_the_same_events_twice_keeps_the_state_and_a_rebuild_reproduces_it(): void
    {
        [$employee, $approver] = $this->users();
        $requestId = $this->uuid();
        $events = [
            $this->requested($requestId, $employee, $approver, 'wf-2'),
            (new PaidLeaveRequestLifecycleReturned(returnedByUserId: $approver, comment: '確認', userId: $employee))->setAggregateRootUuid($requestId),
            (new PaidLeaveRequestLifecycleResubmitted(resubmittedByUserId: $employee, userId: $employee, targetDate: self::DATE))->setAggregateRootUuid($requestId),
            (new PaidLeaveRequestLifecycleApproved(approvedByUserId: $approver, userId: $employee))->setAggregateRootUuid($requestId),
        ];

        foreach ($events as $event) {
            $this->apply($event);
        }
        $first = $this->snapshot($requestId);

        foreach ($events as $event) {
            $this->apply($event);
        }
        $this->assertSame($first, $this->snapshot($requestId), '同じイベントを再適用しても結果が変わらない');

        PaidLeaveRequest::query()->delete();
        foreach ($events as $event) {
            $this->apply($event);
        }
        $this->assertSame($first, $this->snapshot($requestId), '空から再生成しても同じ状態になる');
    }

    /** @return array<string, mixed> */
    private function snapshot(string $requestId): array
    {
        return PaidLeaveRequest::query()->whereKey($requestId)->get([
            'user_id', 'approver_user_id', 'status', 'target_date', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at',
        ])->map(fn (PaidLeaveRequest $row) => $row->getAttributes())->all();
    }
}
