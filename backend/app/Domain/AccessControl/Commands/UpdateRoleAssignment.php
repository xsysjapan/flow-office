<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateRoleAssignment implements Command
{
    public function __construct(public readonly string $assignmentId, public readonly string $scopeType, public readonly ?string $scopeGroupId, public readonly bool $includeDescendants, public readonly ?string $startsAt, public readonly ?string $endsAt, public readonly string $actorUserId) {}
}
