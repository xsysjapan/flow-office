<?php

namespace App\Domain\AccessControl\Aggregates;

use App\Domain\AccessControl\Events\RoleCreated;
use App\Domain\AccessControl\Events\RoleFeaturesChanged;
use App\Domain\AccessControl\Events\RolePermissionsChanged;
use App\Domain\AccessControl\Events\RoleUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class RoleAggregate extends AggregateRoot
{
    public function create(int $id, string $code, string $name, ?string $description, string $actor): self
    {
        $this->recordThat(new RoleCreated($id, $code, $name, $description, $actor));

        return $this;
    }

    public function update(int $roleId, string $name, ?string $description, string $status, string $actor): self
    {
        $this->recordThat(new RoleUpdated($roleId, $name, $description, $status, $actor));

        return $this;
    }

    public function changePermissions(int $roleId, array $permissionIds, string $actor): self
    {
        $this->recordThat(new RolePermissionsChanged($roleId, $permissionIds, $actor));

        return $this;
    }

    public function changeFeatures(int $roleId, array $featureIds, string $actor): self
    {
        $this->recordThat(new RoleFeaturesChanged($roleId, $featureIds, $actor));

        return $this;
    }
}
