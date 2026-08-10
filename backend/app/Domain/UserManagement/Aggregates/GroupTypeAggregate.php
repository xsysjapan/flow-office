<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\GroupTypeCreated;
use App\Domain\UserManagement\Events\GroupTypeUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class GroupTypeAggregate extends AggregateRoot
{
    public function create(int $id, string $code, string $name, int $displayOrder, string $limitType, ?int $maxMemberships, bool $primaryRequired, ?int $maxPrimary, string $actor): self
    {
        $this->recordThat(new GroupTypeCreated($id, $code, $name, $displayOrder, $limitType, $maxMemberships, $primaryRequired, $maxPrimary, $actor));

        return $this;
    }

    public function update(int $id, string $name, int $displayOrder, string $status, string $limitType, ?int $maxMemberships, bool $primaryRequired, ?int $maxPrimary, string $actor): self
    {
        $this->recordThat(new GroupTypeUpdated($id, $name, $displayOrder, $status, $limitType, $maxMemberships, $primaryRequired, $maxPrimary, $actor));

        return $this;
    }
}
