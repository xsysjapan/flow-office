<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\GroupCreated;
use App\Domain\UserManagement\Events\GroupUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class GroupAggregate extends AggregateRoot
{
    public function create(int $typeId, string $name, string $code, ?string $description, ?string $parentId, string $actor): self
    {
        $this->recordThat(new GroupCreated($typeId, $name, $code, $description, $parentId, $actor));

        return $this;
    }

    public function update(string $name, string $code, ?string $description, ?string $parentId, string $status, string $actor): self
    {
        $this->recordThat(new GroupUpdated($name, $code, $description, $parentId, $status, $actor));

        return $this;
    }
}
