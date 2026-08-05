<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompensatoryLeaveRequestShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $workflowRequestId,
    ) {}
}
