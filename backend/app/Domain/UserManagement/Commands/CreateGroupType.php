<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateGroupType implements Command
{
    public function __construct(public readonly string $code, public readonly string $name, public readonly int $displayOrder, public readonly string $membershipLimitType, public readonly ?int $maxMembershipsPerUser, public readonly bool $primaryMembershipRequired, public readonly ?int $maxPrimaryMemberships, public readonly string $actorUserId) {}
}
