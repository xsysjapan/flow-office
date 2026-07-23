<?php

namespace App\Domain\SpecialLeave\Aggregates;

use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsed;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * special_leave_grant集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * SpecialLeaveGrantProjectorに委ねられる。残数(remaining_days)はProjectorが
 * special_leave.usedイベントの累計から都度再計算する(PaidLeaveGrantAggregateと同じ理由。
 * App\Domain\SpecialLeave\Projectors\SpecialLeaveGrantProjector参照)。
 */
class SpecialLeaveGrantAggregate extends AggregateRoot
{
    public function grant(
        string $userId,
        int $specialLeaveTypeId,
        string $grantedOn,
        ?string $expiresOn,
        float $grantedDays,
        ?string $grantReason,
    ): self {
        $this->recordThat(new SpecialLeaveGranted(
            userId: $userId,
            specialLeaveTypeId: $specialLeaveTypeId,
            grantedOn: $grantedOn,
            expiresOn: $expiresOn,
            grantedDays: $grantedDays,
            grantReason: $grantReason,
        ));

        return $this;
    }

    public function use(
        string $userId,
        string $specialLeaveRequestId,
        int $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new SpecialLeaveUsed(
            userId: $userId,
            specialLeaveRequestId: $specialLeaveRequestId,
            attendanceDayId: $attendanceDayId,
            usedOn: $usedOn,
            usedDays: $usedDays,
            usedMinutes: $usedMinutes,
            usageType: $usageType,
        ));

        return $this;
    }
}
