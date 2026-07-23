<?php

namespace App\Domain\PaidLeave\Aggregates;

use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_grant集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * PaidLeaveGrantProjectorに委ねられる。残数(remaining_days)はProjectorが
 * paid_leave.usedイベントの累計から都度再計算する(冪等性のため。
 * App\Domain\PaidLeave\Projectors\PaidLeaveGrantProjector参照)。
 */
class PaidLeaveGrantAggregate extends AggregateRoot
{
    public function grant(
        string $userId,
        string $grantedOn,
        string $expiresOn,
        float $grantedDays,
        ?string $grantReason,
    ): self {
        $this->recordThat(new PaidLeaveGranted(
            userId: $userId,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $grantedDays,
            grantReason: $grantReason,
        ));

        return $this;
    }

    public function use(
        string $userId,
        string $paidLeaveRequestId,
        int $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new PaidLeaveUsed(
            userId: $userId,
            paidLeaveRequestId: $paidLeaveRequestId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
            usageType: $usageType,
        ));

        return $this;
    }

    public function raiseWarning(string $userId, string $warningType, string $message): self
    {
        $this->recordThat(new PaidLeaveWarningRaised(userId: $userId, warningType: $warningType, message: $message));

        return $this;
    }
}
