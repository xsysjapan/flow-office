<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * special_leave.*(申請系)イベントから special_leave_requests を作成・更新する(休暇申請文脈のProjector。
 * 申請テーブルを更新できるのはこのProjectorだけ)。行の存在を前提とする更新は、行が無ければ何もしない。
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
                'status' => SpecialLeaveRequestStatus::SUBMITTED,
                'leave_type' => $event->leaveType,
                'target_date' => $event->targetDate,
                'hours' => $event->hours,
                'requested_days' => $event->requestedDays,
                'reason' => $event->reason,
                'request_group_id' => $event->requestGroupId,
                'submitted_at' => $event->createdAt(),
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

    /** 差戻し後の再提出。申請中に戻す(新しい消化記録は残数側、勤怠の反映は勤怠側が作る)。 */
    public function onSpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::SUBMITTED,
        ]);
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        SpecialLeaveRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => SpecialLeaveRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
