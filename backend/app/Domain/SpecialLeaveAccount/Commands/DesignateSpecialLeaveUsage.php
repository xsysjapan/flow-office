<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇の申請に対応する消化記録を作成する(申請時・再提出時。充当は承認時)。
 *
 * viaReactor=true(休暇申請のイベントからのReactor発行)のとき、同じ申請の有効な消化記録が既にあれば何もしない(冪等)。
 * initiatedByUserIdは連鎖の起点の操作者(申請者・再提出者)。
 */
class DesignateSpecialLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly int $specialLeaveTypeId,
        public readonly string $usedOn,
        public readonly string $usageType,
        public readonly float $usedDays,
        public readonly ?int $usedMinutes,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
