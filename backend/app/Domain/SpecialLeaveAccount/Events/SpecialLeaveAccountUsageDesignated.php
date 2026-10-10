<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 特別休暇の消化記録の作成(special_leave_account.usage_designated。申請時。充当はまだ行わない)。
 * 利用者IDは末尾(Projectorが消化記録の行の利用者を作るために使う)。
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
        public readonly ?string $userId = null,
    ) {}
}
