<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 既存の代休付与・消化記録を口座へ引き継ぐ。口座が空のときに一度だけ記録する
 * (CompensatoryLeaveAccountAggregate::migrate参照)。
 *
 * `grants`: [['grantId', 'source' (sync|manual), 'sourceWorkDate', 'grantedDays', 'grantedMinutes',
 *             'status' (draft|confirmed|cancelled), 'expiresOn'], ...]
 * `usages`: [['usageId', 'requestId', 'usedOn', 'usageType', 'usedDays', 'usedMinutes',
 *             'status' (designated|confirmed), 'allocations' ([['grantId', 'allocatedDays', 'allocatedMinutes']])], ...]
 *
 * @param  array<int, array<string, mixed>>  $grants
 * @param  array<int, array<string, mixed>>  $usages
 */
class CompensatoryLeaveAccountMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly array $grants,
        public readonly array $usages,
    ) {}
}
