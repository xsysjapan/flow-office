<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
