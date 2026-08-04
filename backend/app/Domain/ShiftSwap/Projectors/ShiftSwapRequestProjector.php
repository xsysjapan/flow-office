<?php

namespace App\Domain\ShiftSwap\Projectors;

use App\Domain\ShiftSwap\Events\ShiftSwapRequestApproved;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestCancelled;
use App\Domain\ShiftSwap\Events\ShiftSwapRequested;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestReturned;
use App\Models\ShiftSwapRequest;
use App\Models\ShiftSwapRequestStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * shift_swap.*(申請系)イベントから shift_swap_requests を作成・更新する。
 */
class ShiftSwapRequestProjector extends Projector
{
    public function onShiftSwapRequested(ShiftSwapRequested $event): void
    {
        ShiftSwapRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'target_date' => $event->targetDate,
                'substitute_date' => $event->substituteDate,
                'approver_user_id' => $event->approverUserId,
                'status' => ShiftSwapRequestStatus::SUBMITTED,
                'reason' => $event->reason,
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onShiftSwapRequestApproved(ShiftSwapRequestApproved $event): void
    {
        ShiftSwapRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ShiftSwapRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onShiftSwapRequestReturned(ShiftSwapRequestReturned $event): void
    {
        ShiftSwapRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ShiftSwapRequestStatus::RETURNED,
            'return_comment' => $event->comment,
            'returned_at' => $event->createdAt(),
        ]);
    }

    public function onShiftSwapRequestCancelled(ShiftSwapRequestCancelled $event): void
    {
        ShiftSwapRequest::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => ShiftSwapRequestStatus::CANCELLED,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
