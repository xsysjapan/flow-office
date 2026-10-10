<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 消化記録を確定する(承認時)。`allocations`は充当内訳で、日単位の付与は`allocatedDays`、
 * 時間単位の付与は`allocatedMinutes`に値を持つ(もう一方は0)。
 * `unallocatedDays`・`unallocatedMinutes`は残数不足で充当できなかった分(日単位の消化は日数、
 * 時間単位の消化は分数。不足が無い場合は0)。
 *
 * @param  array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>  $allocations
 */
class CompensatoryLeaveAccountUsageConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageId,
        public readonly array $allocations,
        public readonly float $unallocatedDays = 0.0,
        public readonly int $unallocatedMinutes = 0,
    ) {}
}
