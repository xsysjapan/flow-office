<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * Scheduleエントリの個別修正。修正後は`RecalculateFutureSchedule`による自動再計算の
 * 対象から除外される(依頼書§28)。
 */
class ManuallyEditScheduleEntry implements Command
{
    /**
     * @param  array{scheduledOn?: string, category?: string, candidateGrantDays?: float}  $changes
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $scheduleEntryId,
        public readonly array $changes,
        public readonly string $reason,
        public readonly string $operatorUserId,
    ) {}
}
