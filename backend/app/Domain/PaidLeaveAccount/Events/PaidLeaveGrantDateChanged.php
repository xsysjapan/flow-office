<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantDateChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $newGrantedOn,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
