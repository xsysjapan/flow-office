<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 消化記録を取り消す(差戻し・取消)。`releasedAllocations`は解除した充当内訳
 * (`[['grantId' => string, 'releasedDays' => float], ...]`)。未確定の消化記録の取消は空配列。
 *
 * @param  array<int, array{grantId: string, releasedDays: float}>  $releasedAllocations
 */
class SpecialLeaveAccountUsageCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly array $releasedAllocations,
        public readonly ?string $reason,
    ) {}
}
