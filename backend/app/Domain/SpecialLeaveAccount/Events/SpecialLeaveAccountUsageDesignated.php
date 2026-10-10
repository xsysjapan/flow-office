<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 休暇申請に対応する消化記録を作成する(申請時。まだ付与へは充当していない)。
 * `usageType`は取得単位(全休/半休/時間休)。
 */
class SpecialLeaveAccountUsageDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly string $requestId,
        public readonly int $specialLeaveTypeId,
        public readonly string $usedOn,
        public readonly string $usageType,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
    ) {}
}
