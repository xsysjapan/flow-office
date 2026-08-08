<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupTypeUpdated extends ShouldBeStored
{
    public function __construct(public readonly int $groupTypeId, public readonly string $name, public readonly int $displayOrder, public readonly string $status, public readonly string $membershipLimitType, public readonly ?int $maxMembershipsPerUser, public readonly bool $primaryMembershipRequired, public readonly ?int $maxPrimaryMemberships, public readonly string $actorUserId) {}
}
