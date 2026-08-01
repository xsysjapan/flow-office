<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * special_leave.*(申請系)イベントから special_leave_requests を作成・更新する。
 */
class SpecialLeaveRequestProjector extends Projector
{
    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        SpecialLeaveRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'special_leave_type_id' => $event->specialLeaveTypeId,
                'approver_user_id' => $event->approverUserId,
                'status' => SpecialLeaveRequestStatus::DRAFT,
                'leave_type' => $event->leaveType,
                'target_date' => $event->targetDate,
                'hours' => $event->hours,
                'requested_days' => $event->requestedDays,
                'reason' => $event->reason,
            ],
        );
    }

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::RETURNED,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::SUBMITTED,
            'submitted_at' => $event->createdAt(),
        ]);
    }
}
