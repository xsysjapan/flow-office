<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveAccountGrantAmountChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly float $newGrantedDays,
        public readonly ?string $reason,
        public readonly string $changedByUserId,
    ) {}
}
