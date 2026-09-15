<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 再計算(RecalculateFutureSchedule)により既存のScheduleエントリが置き換えられたことを
 * 記録する。監査のため、置き換え前の内容も保持する。
 */
class PaidLeaveScheduleEntrySuperseded extends ShouldBeStored
{
    public function __construct(
        public readonly string $scheduleEntryId,
        public readonly string $reason,
        public readonly string $previousScheduledOn,
        public readonly string $previousCategory,
        public readonly float $previousCandidateGrantDays,
    ) {}
}
