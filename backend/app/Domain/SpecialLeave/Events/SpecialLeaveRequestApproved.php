<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly string $approvedByUserId,
    ) {}
}
