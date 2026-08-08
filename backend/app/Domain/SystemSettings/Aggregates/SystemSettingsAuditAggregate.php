<?php

namespace App\Domain\SystemSettings\Aggregates;

use App\Domain\SystemSettings\Events\SystemSettingsUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class SystemSettingsAuditAggregate extends AggregateRoot
{
    public function recordUpdate(array $before, array $after, string $actor): self
    {
        $this->recordThat(new SystemSettingsUpdated($before, $after, $actor));

        return $this;
    }
}
