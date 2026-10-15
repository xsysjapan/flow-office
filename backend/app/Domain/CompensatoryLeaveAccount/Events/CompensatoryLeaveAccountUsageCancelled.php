<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 消化記録を取り消す(差戻し・取消)。確定済みだった場合は`releasedAllocations`の充当を解除して残数を戻す。
 *
 * @param  array<int, array{grantId: string, releasedDays: float, releasedMinutes: int}>  $releasedAllocations
 */
class CompensatoryLeaveAccountUsageCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageId,
        public readonly array $releasedAllocations,
        public readonly ?string $reason,
    ) {}
}
