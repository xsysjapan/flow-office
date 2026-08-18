<?php

namespace App\Domain\CompensatoryLeave\Aggregates;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantConfirmed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantRemoved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantSynced;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveManuallyGranted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageReversed;
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

    /**
     * 管理者が休日出勤の対象日を指定して代休を手動付与する。sync()と異なり
     * attendance_day_idに紐づかず(nullのまま)、承認不要でこの1イベントのみで
     * status=confirmedの行を作成する(GrantCompensatoryLeaveHandler参照)。
     */
    public function grantManually(
        string $userId,
        string $workDate,
        float $grantedDays,
        ?int $grantedMinutes,
        ?string $expiresOn,
        ?string $grantReason,
    ): self {
        $this->recordThat(new CompensatoryLeaveManuallyGranted(
            userId: $userId,
            workDate: $workDate,
            grantedDays: $grantedDays,
            grantedMinutes: $grantedMinutes,
            expiresOn: $expiresOn,
            grantReason: $grantReason,
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

    /**
     * 承認済みの代休消化申請が取り消された際、この付与への消化を取り消す(残数を戻す)。
     * `use()`で消化した内容をそのまま`CompensatoryLeaveUsageReversed`として記録し、
     * Projectorが差分から`used_days`/`used_minutes`/`remaining_days`/`remaining_minutes`を
     * 再計算する(PaidLeaveGrantAggregate::reverseUsageと同じ考え方)。
     */
    public function reverseUsage(
        string $userId,
        string $compensatoryLeaveRequestId,
        string $attendanceDayId,
        string $usedOn,
        float $usedDays,
        ?int $usedMinutes,
        string $usageType,
    ): self {
        $this->recordThat(new CompensatoryLeaveUsageReversed(
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
