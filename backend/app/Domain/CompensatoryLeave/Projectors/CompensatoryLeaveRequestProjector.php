<?php

namespace App\Domain\CompensatoryLeave\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * compensatory_leave.*(消化申請系)イベントから compensatory_leave_requests を作成・更新する
 * (SpecialLeaveRequestProjectorと同じ理由)。
 */
class CompensatoryLeaveRequestProjector extends Projector
{
    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        CompensatoryLeaveRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'approver_user_id' => $event->approverUserId,
                'status' => CompensatoryLeaveRequestStatus::SUBMITTED,
                'leave_type' => $event->leaveType,
                'target_date' => $event->targetDate,
                'hours' => $event->hours,
                'requested_days' => $event->requestedDays,
                'requested_minutes' => $event->requestedMinutes,
                'reason' => $event->reason,
                'request_group_id' => $event->requestGroupId,
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        CompensatoryLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        CompensatoryLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        CompensatoryLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
