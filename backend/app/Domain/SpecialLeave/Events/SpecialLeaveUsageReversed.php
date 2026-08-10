<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 承認済みの特別休暇申請が取り消された際、消化済みのspecial_leave_grantへ記録する
 * (special_leave.usedの取消)。フィールドは対応するSpecialLeaveUsedと同じ形にし、
 * Projectorが差分計算・special_leave_usages行の特定に使う。
 */
class SpecialLeaveUsageReversed extends ShouldBeStored
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
