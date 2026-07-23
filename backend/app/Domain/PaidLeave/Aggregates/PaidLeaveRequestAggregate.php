<?php

namespace App\Domain\PaidLeave\Aggregates;

use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestCancelled;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_request集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * PaidLeaveRequestProjectorに委ねられる。業務ルール判定(残数充足・承認者一致等)は
 * HandlerがEloquent Projectionの現在値を読んで行う(Workflow/BackOfficeと同じ理由。
 * テストがPaidLeaveRequest::query()->create()でイベントを経由せず直接rowを作成することが
 * あるため、集約の再生状態は信頼できない)。
 */
class PaidLeaveRequestAggregate extends AggregateRoot
{
    public function request(
        int $userId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        int $approverUserId,
        ?string $reason,
    ): self {
        $this->recordThat(new PaidLeaveRequested(
            userId: $userId,
            targetDate: $targetDate,
            leaveType: $leaveType,
            hours: $hours,
            requestedDays: $requestedDays,
            approverUserId: $approverUserId,
            reason: $reason,
        ));

        return $this;
    }

    public function approve(int $approvedByUserId): self
    {
        $this->recordThat(new PaidLeaveRequestApproved(approvedByUserId: $approvedByUserId));

        return $this;
    }

    public function returnRequest(int $returnedByUserId, string $comment): self
    {
        $this->recordThat(new PaidLeaveRequestReturned(returnedByUserId: $returnedByUserId, comment: $comment));

        return $this;
    }

    public function cancel(int $cancelledByUserId): self
    {
        $this->recordThat(new PaidLeaveRequestCancelled(cancelledByUserId: $cancelledByUserId));

        return $this;
    }
}
