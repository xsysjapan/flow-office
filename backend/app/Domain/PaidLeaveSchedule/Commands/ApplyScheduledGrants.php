<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者の一括付与操作。対象は`Eligible`状態のScheduleエントリのみ
 * (依頼書§39「一括付与」。Not Eligible/NeedsReviewは対象外)。
 */
class ApplyScheduledGrants implements Command
{
    /**
     * @param  array<int, string>  $scheduleEntryIds
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $scheduleEntryIds,
        public readonly string $operatorUserId,
    ) {}
}
