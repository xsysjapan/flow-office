<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RemoveRoleAssignment implements Command
{
    public function __construct(public readonly string $assignmentId, public readonly string $actorUserId) {}
}
