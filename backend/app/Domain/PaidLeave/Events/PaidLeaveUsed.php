<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * UC-P004 step5: 有効期限が近い付与分(paid_leave_grants、=集約ルート)から消化する。
 * 1件の有給申請の承認が複数grantにまたがる場合、grantごとに1つ記録される。
 */
class PaidLeaveUsed extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $paidLeaveRequestId,
        public readonly int $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly string $usageType,
    ) {}
}
