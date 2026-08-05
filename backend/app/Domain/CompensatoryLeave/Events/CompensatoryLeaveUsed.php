<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.used
 *
 * 失効日が近い確定済みGrant(集約ルート)から消化する。1件の代休消化申請の承認が
 * 複数grantにまたがる場合、grantごとに1つ記録される(SpecialLeaveUsedと同じ考え方)。
 */
class CompensatoryLeaveUsed extends ShouldBeStored
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
