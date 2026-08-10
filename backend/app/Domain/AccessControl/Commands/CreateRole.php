<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateRole implements Command
{
    public function __construct(public readonly string $code, public readonly string $name, public readonly ?string $description, public readonly string $actorUserId) {}
}
