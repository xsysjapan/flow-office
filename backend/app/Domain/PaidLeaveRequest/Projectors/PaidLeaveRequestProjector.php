<?php

namespace App\Domain\PaidLeaveRequest\Projectors;

use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 有給申請の申請状態を `paid_leave_requests` に作る(休暇申請文脈のProjector。
 * 論点4・論点13・仕様確定事項C。.claude/skills/add-projection 参照)。
 *
 * 行のキーは有給申請ID(有給の申請集約ID=`paid_leave_request.*`の集約ID)。入力は
 * `paid_leave_request.*`だけで、口座(`paid_leave_account.*`)やワークフローのイベントは読まない。
 * 差戻し・取消でも行は削除せず statusを変える。
 *
 * 全て upsert/update で書くため、同じイベント列を再適用しても、空から再生成しても同じ状態になる。
 * 状態のイベントは行が無ければ何もしない(再生の順序に依存させない)。
 */
class PaidLeaveRequestProjector extends Projector
{
    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        PaidLeaveRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'approver_user_id' => $this->approverOf($event->approverUserId, $event->userId),
                'status' => PaidLeaveRequestStatus::SUBMITTED,
                'leave_type' => $event->leaveType,
                'target_date' => $event->targetDate,
                'hours' => $event->hours,
                'requested_days' => $event->requestedDays,
                'reason' => $event->reason,
                'request_group_id' => $event->requestGroupId,
                'submitted_at' => $event->createdAt(),
                'approved_at' => null,
                'returned_at' => null,
                'cancelled_at' => null,
            ],
        );
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        $this->transition($event->aggregateRootUuid(), [
            'status' => PaidLeaveRequestStatus::SUBMITTED,
            'submitted_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->transition($event->aggregateRootUuid(), [
            'status' => PaidLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->transition($event->aggregateRootUuid(), [
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->transition($event->aggregateRootUuid(), [
            'status' => PaidLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }

    /**
     * 承認者が無い申請(承認不要時)は申請者をプレースホルダにする(コントローラの現行と同じ。
     * approver_user_idはNOT NULLの外部キーのため)。
     */
    private function approverOf(?string $approverUserId, string $userId): string
    {
        return $approverUserId ?? $userId;
    }

    /**
     * 既存行の状態を更新する。行が無ければ何もしない。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(string $requestId, array $attributes): void
    {
        PaidLeaveRequest::query()->whereKey($requestId)->update($attributes);
    }
}
