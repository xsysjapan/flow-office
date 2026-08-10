<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 承認済みの代休消化申請が取り消された際、消化済みのcompensatory_leave_grantへ記録する
 * (compensatory_leave.usedの取消)。フィールドは対応するCompensatoryLeaveUsedと同じ形にし、
 * Projectorが差分計算・compensatory_leave_usages行の特定に使う(PaidLeaveUsageReversedと
 * 同じ考え方)。
 */
class CompensatoryLeaveUsageReversed extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $compensatoryLeaveRequestId,
        public readonly string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly string $usageType,
    ) {}
}
