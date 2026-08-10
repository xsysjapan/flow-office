<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RemoveUserFeatureSuspension implements Command
{
    public function __construct(public readonly string $suspensionId, public readonly string $actorUserId) {}
}
