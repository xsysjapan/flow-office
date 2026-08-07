<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 申請時点(承認前)で対象日を特別休暇として設定したことを記録する。special_leave_request
 * 集約が記録するイベントであり、この時点ではどのspecial_leave_grantから消化するかは
 * まだ決まっていない(承認時のSpecialLeaveUsed参照)。
 */
class SpecialLeaveUsageDesignated extends ShouldBeStored
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
