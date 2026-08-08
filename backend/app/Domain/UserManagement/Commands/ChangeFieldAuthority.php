<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ChangeFieldAuthority implements Command
{
    public function __construct(public readonly string $fieldKey, public readonly string $authorityType, public readonly ?string $provider, public readonly string $actorUserId) {}
}
