<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveRequestShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $workflowRequestId,
    ) {}
}
