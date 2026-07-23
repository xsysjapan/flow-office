<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class WorkflowRequestCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
        public readonly string $reason,
    ) {}
}
