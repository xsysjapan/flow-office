<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * `EnsureFutureScheduleGenerated`によりScheduleエントリが新規作成された。
 */
class PaidLeaveScheduleEntryCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly string $scheduledOn,
        public readonly string $category,
        public readonly float $candidateGrantDays,
    ) {}
}
