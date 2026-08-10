<?php

namespace App\Domain\CompensatoryLeave\Aggregates;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageDesignated;
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
        ?string $requestGroupId = null,
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
            requestGroupId: $requestGroupId,
        ));

        return $this;
    }

    /**
     * 申請時点(承認前)で対象日の勤怠を代休として設定したことを記録する
     * (CompensatoryLeaveGrantProjectorがcompensatory_leave_usagesへgrant_id未確定・
     * is_confirmed=falseの行を作る)。承認時にどのcompensatory_leave_grantから消化するかが
     * 決まった時点で、この行が確定済みへ更新される(compensatory_leave.used。
     * ApproveCompensatoryLeaveRequestHandler参照。PaidLeaveRequestAggregate::designateUsageと
     * 同じ考え方)。
     */
    public function designateUsage(
        string $userId,
        string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new CompensatoryLeaveUsageDesignated(
            userId: $userId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
            usageType: $usageType,
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
