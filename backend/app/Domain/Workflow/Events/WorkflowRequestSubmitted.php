<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class WorkflowRequestSubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly int $approverUserId,
        public readonly int $submittedByUserId,
    ) {}
}
