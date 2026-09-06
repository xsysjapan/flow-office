<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveUsageCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly ?string $cancelledByUserId,
        public readonly ?string $reason,
    ) {}
}
