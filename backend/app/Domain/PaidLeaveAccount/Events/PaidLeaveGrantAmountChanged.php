<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantAmountChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly float $newGrantedDays,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
