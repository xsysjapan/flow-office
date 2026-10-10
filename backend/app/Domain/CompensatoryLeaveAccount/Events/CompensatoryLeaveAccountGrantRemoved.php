<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 休日出勤でなくなった実績から同期された下書きの付与を削除する。確定済みの付与は削除しない。
 */
class CompensatoryLeaveAccountGrantRemoved extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $reason,
    ) {}
}
