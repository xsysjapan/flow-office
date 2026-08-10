<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class LinkExternalIdentity implements Command
{
    public function __construct(public readonly string $userId, public readonly string $provider, public readonly ?string $externalTenantId, public readonly string $externalSubjectId, public readonly ?string $externalCode, public readonly ?string $email, public readonly string $actorUserId) {}
}
