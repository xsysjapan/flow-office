<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 管理者が自動判定結果を上書きした(依頼書§40)。
 */
class PaidLeaveScheduleAssessmentOverridden extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly string $finalResult,
        public readonly string $reason,
        public readonly string $byUserId,
        public readonly string $at,
    ) {}
}
