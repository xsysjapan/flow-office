<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BackOfficeTaskAssigned extends ShouldBeStored
{
    public function __construct(
        public readonly int $assignedUserId,
        public readonly int $assignedByUserId,
    ) {}
}
