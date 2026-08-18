<?php

namespace App\Domain\PaidLeave\Aggregates;

use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Domain\PaidLeave\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeave\Events\PaidLeaveUsageReversed;
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
        string $attendanceDayId,
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

    /**
     * 承認済みの有給申請が取り消された際、この付与への消化を取り消す(残数を戻す)。
     * `use()`で消化した内容をそのまま`PaidLeaveUsageReversed`として記録し、
     * Projectorが差分から`used_days`/`remaining_days`を再計算する。
     */
    public function reverseUsage(
        string $userId,
        string $paidLeaveRequestId,
        string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new PaidLeaveUsageReversed(
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

    /**
     * 管理者が未消化の付与を取り消す。消化済み日数があるかどうかはHandler側で検証済み。
     */
    public function revoke(string $revokedByUserId, ?string $reason): self
    {
        $this->recordThat(new PaidLeaveGrantRevoked(revokedByUserId: $revokedByUserId, reason: $reason));

        return $this;
    }
}
