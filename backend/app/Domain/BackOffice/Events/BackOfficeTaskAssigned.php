<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BackOfficeTaskAssigned extends ShouldBeStored
{
    public function __construct(
        public readonly string $assignedUserId,
        public readonly string $assignedByUserId,
    ) {}
}
