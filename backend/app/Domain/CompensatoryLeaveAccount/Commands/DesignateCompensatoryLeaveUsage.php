<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休申請に対応する消化記録を作成する(申請時。充当は行わない)。
 * viaReactor=true(申請・再提出からのReactor発行)のとき、同じ申請の有効な消化記録が既にあれば何もしない(冪等)。
 */
class DesignateCompensatoryLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly string $usedOn,
        public readonly string $usageType,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
