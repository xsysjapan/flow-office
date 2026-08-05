<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompensatoryLeaveRequestApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
    ) {}
}
