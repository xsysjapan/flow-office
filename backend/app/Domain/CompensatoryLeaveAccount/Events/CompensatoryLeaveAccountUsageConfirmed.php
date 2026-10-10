<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 消化記録を確定する(承認時)。`allocations`は充当内訳で、日単位の付与は`allocatedDays`、
 * 時間単位の付与は`allocatedMinutes`に値を持つ(もう一方は0)。
 *
 * @param  array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>  $allocations
 */
class CompensatoryLeaveAccountUsageConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly array $allocations,
    ) {}
}
