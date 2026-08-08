<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class AssignFeatureToGroup implements Command
{
    public function __construct(public readonly string $groupId, public readonly int $featureId, public readonly string $actorUserId) {}
}
