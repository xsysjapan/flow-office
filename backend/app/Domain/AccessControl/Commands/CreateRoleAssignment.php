<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateRoleAssignment implements Command
{
    public function __construct(public readonly string $assignmentId, public readonly string $subjectType, public readonly string $subjectId, public readonly int $roleId, public readonly string $scopeType, public readonly ?string $scopeGroupId, public readonly bool $includeDescendants, public readonly ?string $startsAt, public readonly ?string $endsAt, public readonly string $actorUserId) {}
}
