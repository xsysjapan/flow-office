<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveRequestShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $workflowRequestId,
    ) {}
}
