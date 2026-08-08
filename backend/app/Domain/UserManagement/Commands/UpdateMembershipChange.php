<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateMembershipChange implements Command
{
    public function __construct(public readonly string $changeSetId, public readonly string $userId, public readonly string $effectiveAt, public readonly string $sourceType, public readonly array $items, public readonly ?string $note, public readonly string $actorUserId) {}
}
