<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CancelMembershipChange implements Command
{
    public function __construct(public readonly string $changeSetId, public readonly string $actorUserId) {}
}
