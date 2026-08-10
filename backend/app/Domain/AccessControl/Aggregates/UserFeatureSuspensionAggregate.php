<?php

namespace App\Domain\AccessControl\Aggregates;

use App\Domain\AccessControl\Events\UserFeatureSuspended;
use App\Domain\AccessControl\Events\UserFeatureSuspensionRemoved;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class UserFeatureSuspensionAggregate extends AggregateRoot
{
    public function suspend(string $suspensionId, string $userId, int $featureId, string $reason, ?string $startsAt, ?string $endsAt, string $actor): self
    {
        $this->recordThat(new UserFeatureSuspended($suspensionId, $userId, $featureId, $reason, $startsAt, $endsAt, $actor));

        return $this;
    }

    public function remove(string $suspensionId, string $userId, string $actor): self
    {
        $this->recordThat(new UserFeatureSuspensionRemoved($suspensionId, $userId, $actor));

        return $this;
    }
}
