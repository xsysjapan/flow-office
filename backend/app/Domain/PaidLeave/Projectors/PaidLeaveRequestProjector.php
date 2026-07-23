<?php

namespace App\Domain\PaidLeave\Projectors;

use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * paid_leave.*(申請系)イベントから paid_leave_requests を作成・更新する。
 */
class PaidLeaveRequestProjector extends Projector
{
    public function onPaidLeaveRequested(PaidLeaveRequested $event): void
    {
        PaidLeaveRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'approver_user_id' => $event->approverUserId,
                'status' => PaidLeaveRequestStatus::SUBMITTED,
                'leave_type' => $event->leaveType,
                'target_date' => $event->targetDate,
                'hours' => $event->hours,
                'requested_days' => $event->requestedDays,
                'reason' => $event->reason,
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onPaidLeaveRequestApproved(PaidLeaveRequestApproved $event): void
    {
        PaidLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestReturned(PaidLeaveRequestReturned $event): void
    {
        PaidLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onPaidLeaveRequestCancelled(PaidLeaveRequestCancelled $event): void
    {
        PaidLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => PaidLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
