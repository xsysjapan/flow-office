<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class WorkflowRequestRejected extends ShouldBeStored
{
    public function __construct(
        public readonly string $rejectedByUserId,
        public readonly string $reason,
    ) {}
}
