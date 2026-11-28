<?php

namespace App\Domain\AccessControl\Aggregates;

use App\Domain\AccessControl\Events\RoleAssignmentCreated;
use App\Domain\AccessControl\Events\RoleAssignmentRemoved;
use App\Domain\AccessControl\Events\RoleAssignmentUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class RoleAssignmentAggregate extends AggregateRoot
{
    private bool $created = false;

    public function isCreated(): bool
    {
        return $this->created;
    }

    protected function applyRoleAssignmentCreated(RoleAssignmentCreated $event): void
    {
        $this->created = true;
    }

    public function create(string $subjectType, string $subjectId, int $roleId, string $scopeType, ?string $scopeGroupId, bool $includesDescendants, ?string $startsAt, ?string $endsAt, ?string $actor): self
    {
        $this->recordThat(new RoleAssignmentCreated($subjectType, $subjectId, $roleId, $scopeType, $scopeGroupId, $includesDescendants, $startsAt, $endsAt, $actor));

        return $this;
    }

    public function remove(?string $actor): self
    {
        $this->recordThat(new RoleAssignmentRemoved($actor));

        return $this;
    }

    public function update(string $scopeType, ?string $scopeGroupId, bool $includesDescendants, ?string $startsAt, ?string $endsAt, ?string $actor): self
    {
        $this->recordThat(new RoleAssignmentUpdated($scopeType, $scopeGroupId, $includesDescendants, $startsAt, $endsAt, $actor));

        return $this;
    }
}
