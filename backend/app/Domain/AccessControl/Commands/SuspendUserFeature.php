<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class SuspendUserFeature implements Command
{
    public function __construct(public readonly string $userId, public readonly int $featureId, public readonly string $reason, public readonly ?string $startsAt, public readonly ?string $endsAt, public readonly string $actorUserId) {}
}
