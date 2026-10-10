<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 代休の休暇申請に対応する消化記録を作成する(申請時。充当はまだ行わない)。
 * 新規の消化記録には勤怠日を持たせない(利用者×日付で勤怠と対応づける)。
 */
class CompensatoryLeaveAccountUsageDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageId,
        public readonly string $requestId,
        public readonly string $usedOn,
        public readonly string $usageType,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
    ) {}
}
