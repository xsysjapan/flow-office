<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangeRoleFeatures implements Command
{
    public function __construct(public readonly int $roleId, public readonly array $featureIds, public readonly string $actorUserId) {}
}
