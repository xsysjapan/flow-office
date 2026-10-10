<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 消化記録を確定する(承認時)。`allocations`は充当内訳
 * (`[['grantId' => string, 'allocatedDays' => float], ...]`)。残数を要しない種別は空配列。
 *
 * @param  array<int, array{grantId: string, allocatedDays: float}>  $allocations
 */
class SpecialLeaveAccountUsageConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly array $allocations,
    ) {}
}
