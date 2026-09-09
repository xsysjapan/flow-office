<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveUsageAllocated extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly string $grantId,
        public readonly float $allocatedDays,
    ) {}
}
