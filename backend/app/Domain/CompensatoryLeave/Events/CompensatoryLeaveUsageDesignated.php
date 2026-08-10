<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 申請時点(承認前)で対象日を代休として設定したことを記録する。compensatory_leave_request
 * 集約が記録するイベントであり、この時点ではどのcompensatory_leave_grantから消化するかは
 * まだ決まっていない(承認時のCompensatoryLeaveUsed参照。PaidLeaveUsageDesignatedと同じ考え方)。
 */
class CompensatoryLeaveUsageDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly string $usageType,
    ) {}
}
