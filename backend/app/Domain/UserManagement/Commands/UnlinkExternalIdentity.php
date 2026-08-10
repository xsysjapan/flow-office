<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UnlinkExternalIdentity implements Command
{
    public function __construct(public readonly int $identityId, public readonly string $actorUserId) {}
}
