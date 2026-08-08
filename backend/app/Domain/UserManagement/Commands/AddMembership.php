<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class AddMembership implements Command
{
    public function __construct(public readonly string $userId, public readonly string $groupId, public readonly string $membershipKind, public readonly bool $isPrimary, public readonly string $actorUserId) {}
}
