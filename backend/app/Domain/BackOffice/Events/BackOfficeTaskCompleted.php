<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BackOfficeTaskCompleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $completedByUserId,
        public readonly ?string $comment,
    ) {}
}
