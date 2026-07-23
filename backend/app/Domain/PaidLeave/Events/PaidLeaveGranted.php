<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGranted extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
