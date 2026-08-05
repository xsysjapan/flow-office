<?php

namespace App\Domain\CompensatoryLeave\Aggregates;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * compensatory_leave_request集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * CompensatoryLeaveRequestProjectorに委ねられる(SpecialLeaveRequestAggregateと同じ理由)。
 */
class CompensatoryLeaveRequestAggregate extends AggregateRoot
{
    public function request(
        string $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?int $requestedMinutes,
        string $approverUserId,
        ?string $reason,
    ): self {
        $this->recordThat(new CompensatoryLeaveRequested(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            requestedMinutes: $requestedMinutes,
            approverUserId: $approverUserId,
            reason: $reason,
        ));

        return $this;
    }

    public function approve(?string $approvedByUserId): self
    {
        $this->recordThat(new CompensatoryLeaveRequestApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnRequest(string $returnedByUserId, string $comment): self
    {
        $this->recordThat(new CompensatoryLeaveRequestReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(string $cancelledByUserId): self
    {
        $this->recordThat(new CompensatoryLeaveRequestCancelled(cancelledByUserId: $cancelledByUserId));

        return $this;
    }

    public function share(string $workflowRequestId): self
    {
        $this->recordThat(new CompensatoryLeaveRequestShared(workflowRequestId: $workflowRequestId));

        return $this;
    }
}
