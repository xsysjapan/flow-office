<?php

namespace App\Domain\AccessControl\Aggregates;

use App\Domain\AccessControl\Events\FeatureAssignedToGroup;
use App\Domain\AccessControl\Events\FeatureRemovedFromGroup;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class GroupAccessAggregate extends AggregateRoot
{
    public function assignFeature(string $groupId, int $featureId, string $actor): self
    {
        $this->recordThat(new FeatureAssignedToGroup($groupId, $featureId, $actor));

        return $this;
    }

    public function removeFeature(string $groupId, int $featureId, string $actor): self
    {
        $this->recordThat(new FeatureRemovedFromGroup($groupId, $featureId, $actor));

        return $this;
    }
}
