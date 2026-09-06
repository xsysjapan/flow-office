<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
        public readonly string $source,
    ) {}
}
