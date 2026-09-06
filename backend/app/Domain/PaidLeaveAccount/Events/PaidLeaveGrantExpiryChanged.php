<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantExpiryChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $newExpiresOn,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
