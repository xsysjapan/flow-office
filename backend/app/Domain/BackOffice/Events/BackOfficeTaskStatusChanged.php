<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BackOfficeTaskStatusChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly string $changedByUserId,
        public readonly ?string $comment,
    ) {}
}
