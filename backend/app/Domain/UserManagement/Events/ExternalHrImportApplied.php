<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ExternalHrImportApplied extends ShouldBeStored
{
    public function __construct(public readonly array $rows, public readonly string $actorUserId) {}
}
