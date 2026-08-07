<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 申請時点(承認前)で対象日を有給休暇として設定したことを記録する。paid_leave_request
 * 集約が記録するイベントであり、この時点ではどのpaid_leave_grantから消化するかは
 * まだ決まっていない(承認時のPaidLeaveUsed参照)。
 */
class PaidLeaveUsageDesignated extends ShouldBeStored
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
