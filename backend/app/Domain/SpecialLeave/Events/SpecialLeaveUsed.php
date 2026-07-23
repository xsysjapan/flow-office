<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 失効日が近い付与分(special_leave_grants、無期限は最後、=集約ルート)から消化する。
 * 1件の特別休暇申請の承認が複数grantにまたがる場合、grantごとに1つ記録される。
 */
class SpecialLeaveUsed extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $specialLeaveRequestId,
        public readonly string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly string $usageType,
    ) {}
}
