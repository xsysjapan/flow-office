<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveUsageAllocationReleased extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly string $grantId,
        public readonly float $releasedDays,
    ) {}
}
