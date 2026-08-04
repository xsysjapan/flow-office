<?php

namespace App\Domain\ShiftSwap\Aggregates;

use App\Domain\ShiftSwap\Events\ShiftSwapRequestApproved;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestCancelled;
use App\Domain\ShiftSwap\Events\ShiftSwapRequested;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestReturned;
use App\Domain\ShiftSwap\Events\ShiftSwapRequestShared;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * shift_swap_request集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * ShiftSwapRequestProjectorに委ねられる。業務ルール判定(勤務予定の休日区分・週40時間等)は
 * HandlerがEloquent Projectionの現在値を読んで行う(SpecialLeaveRequestAggregateと同じ理由)。
 */
class ShiftSwapRequestAggregate extends AggregateRoot
{
    public function request(
        string $userId,
        string $targetDate,
        string $substituteDate,
        ?string $approverUserId,
        ?string $reason,
    ): self {
        $this->recordThat(new ShiftSwapRequested(
            userId: $userId,
            targetDate: $targetDate,
            substituteDate: $substituteDate,
            approverUserId: $approverUserId,
            reason: $reason,
        ));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->recordThat(new ShiftSwapRequestApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new ShiftSwapRequestReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(string $cancelledByUserId): self
    {
        $this->recordThat(new ShiftSwapRequestCancelled(cancelledByUserId: $cancelledByUserId));

        return $this;
    }

    public function share(string $workflowRequestId): self
    {
        $this->recordThat(new ShiftSwapRequestShared(workflowRequestId: $workflowRequestId));

        return $this;
    }
}
