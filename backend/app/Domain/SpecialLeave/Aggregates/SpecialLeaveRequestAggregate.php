<?php

namespace App\Domain\SpecialLeave\Aggregates;

use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * special_leave_request集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * SpecialLeaveRequestProjectorに委ねられる。業務ルール判定(残数充足・承認者一致等)は
 * HandlerがEloquent Projectionの現在値を読んで行う(PaidLeaveRequestAggregateと同じ理由)。
 */
class SpecialLeaveRequestAggregate extends AggregateRoot
{
    public function request(
        string $userId,
        int $specialLeaveTypeId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        string $approverUserId,
        ?string $reason,
    ): self {
        $this->recordThat(new SpecialLeaveRequested(
            userId: $userId,
            specialLeaveTypeId: $specialLeaveTypeId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            approverUserId: $approverUserId,
            reason: $reason,
        ));

        return $this;
    }

    public function approve(string $approvedByUserId): self
    {
        $this->recordThat(new SpecialLeaveRequestApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new SpecialLeaveRequestReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(string $cancelledByUserId): self
    {
        $this->recordThat(new SpecialLeaveRequestCancelled(cancelledByUserId: $cancelledByUserId));

        return $this;
    }
}
