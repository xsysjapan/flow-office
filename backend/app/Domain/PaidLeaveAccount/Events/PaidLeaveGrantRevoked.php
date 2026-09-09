<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
