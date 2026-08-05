<?php

namespace App\Domain\CompensatoryLeave\Aggregates;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantConfirmed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantRemoved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantSynced;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsed;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * compensatory_leave_grant集約。主キー(compensatory_leave_grants.id)はコマンド側/
 * Handlerが決めたUUIDで、行の新規作成自体もCompensatoryLeaveGrantProjectorに委ねられる
 * (SpecialLeaveGrantAggregateと同じ理由)。
 */
class CompensatoryLeaveGrantAggregate extends AggregateRoot
{
    public function sync(
        string $userId,
        string $attendanceDayId,
        string $workDate,
        float $grantedDays,
        ?int $grantedMinutes,
    ): self {
        $this->recordThat(new CompensatoryLeaveGrantSynced(
            userId: $userId,
            attendanceDayId: $attendanceDayId,
            workDate: $workDate,
            grantedDays: $grantedDays,
            grantedMinutes: $grantedMinutes,
        ));

        return $this;
    }

    public function remove(string $reason): self
    {
        $this->recordThat(new CompensatoryLeaveGrantRemoved(reason: $reason));

        return $this;
    }

    public function confirm(string $confirmedAt, ?string $expiresOn): self
    {
        $this->recordThat(new CompensatoryLeaveGrantConfirmed(confirmedAt: $confirmedAt, expiresOn: $expiresOn));

        return $this;
    }

    public function cancel(string $cancelledByUserId, ?string $reason): self
    {
        $this->recordThat(new CompensatoryLeaveGrantCancelled(cancelledByUserId: $cancelledByUserId, reason: $reason));

        return $this;
    }

    public function use(
        string $userId,
        string $compensatoryLeaveRequestId,
        string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new CompensatoryLeaveUsed(
            userId: $userId,
            compensatoryLeaveRequestId: $compensatoryLeaveRequestId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
            usageType: $usageType,
        ));

        return $this;
    }
}
