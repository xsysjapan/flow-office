<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 管理者がScheduleエントリの区分・候補付与日数を手動修正した。以後、
 * `RecalculateFutureSchedule`から保護される(`manualOverride`フラグが立つ、spec.md論点7)。
 */
class PaidLeaveScheduleEntryManuallyEdited extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly ?string $category,
        public readonly ?float $candidateGrantDays,
        public readonly string $reason,
        public readonly string $byUserId,
        public readonly string $at,
    ) {}
}
