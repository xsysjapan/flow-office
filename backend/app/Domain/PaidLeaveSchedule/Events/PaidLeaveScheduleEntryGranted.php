<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 管理者の一括付与操作(`ApplyScheduledGrants`)により、`PaidLeaveAccountAggregate::grant()`
 * が成功した後にScheduleエントリ側をGrantedへ遷移させる。
 */
class PaidLeaveScheduleEntryGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $grantId,
        public readonly string $operatorUserId,
    ) {}
}
