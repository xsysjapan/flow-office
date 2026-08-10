<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RemoveMembership implements Command
{
    public function __construct(public readonly string $userId, public readonly string $groupId, public readonly string $actorUserId) {}
}
