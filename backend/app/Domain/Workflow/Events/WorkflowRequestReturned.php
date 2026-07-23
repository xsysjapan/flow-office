<?php

namespace App\Domain\Workflow\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class WorkflowRequestReturned extends ShouldBeStored
{
    public function __construct(
        public readonly int $returnedByUserId,
        public readonly string $comment,
    ) {}
}
