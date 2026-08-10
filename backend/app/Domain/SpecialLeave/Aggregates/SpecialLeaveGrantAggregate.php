<?php

namespace App\Domain\SpecialLeave\Aggregates;

use App\Domain\SpecialLeave\Events\SpecialLeaveGranted;
use App\Domain\SpecialLeave\Events\SpecialLeaveUsageReversed;
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
        string $attendanceDayId,
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

    /**
     * 承認済みの特別休暇申請が取り消された際、この付与への消化を取り消す(残数を戻す)。
     * `use()`で消化した内容をそのまま`SpecialLeaveUsageReversed`として記録し、
     * Projectorが差分から`used_days`/`remaining_days`を再計算する。
     */
    public function reverseUsage(
        string $userId,
        string $specialLeaveRequestId,
        string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new SpecialLeaveUsageReversed(
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
