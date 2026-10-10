<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 既存の付与・消化記録を口座へ引き継ぐ。口座が空のときに一度だけ記録する
 * (SpecialLeaveAccountAggregate::migrate参照)。
 *
 * `grants`: [['grantId', 'specialLeaveTypeId', 'grantedOn', 'expiresOn', 'grantedDays', 'revoked'], ...]
 * `usages`: [['usageId', 'requestId', 'specialLeaveTypeId', 'usedOn', 'usageType', 'usedDays',
 *             'usedMinutes', 'status' (designated|confirmed), 'allocations' ([['grantId', 'allocatedDays']])], ...]
 *
 * @param  array<int, array<string, mixed>>  $grants
 * @param  array<int, array<string, mixed>>  $usages
 */
class SpecialLeaveAccountMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly array $grants,
        public readonly array $usages,
    ) {}
}
