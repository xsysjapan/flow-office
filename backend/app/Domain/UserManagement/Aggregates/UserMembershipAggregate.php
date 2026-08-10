<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\MembershipAdded;
use App\Domain\UserManagement\Events\MembershipPrimaryChanged;
use App\Domain\UserManagement\Events\MembershipRemoved;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class UserMembershipAggregate extends AggregateRoot
{
    public function add(string $userId, string $groupId, string $kind, bool $primary, string $actor): self
    {
        $this->recordThat(new MembershipAdded($userId, $groupId, $kind, $primary, $actor));

        return $this;
    }

    public function remove(string $userId, string $groupId, string $actor): self
    {
        $this->recordThat(new MembershipRemoved($userId, $groupId, $actor));

        return $this;
    }

    public function changePrimary(string $userId, string $groupId, bool $isPrimary, string $actor): self
    {
        $this->recordThat(new MembershipPrimaryChanged($userId, $groupId, $isPrimary, $actor));

        return $this;
    }
}
