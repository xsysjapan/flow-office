<?php

namespace App\Domain\UserManagement\Aggregates;

use App\Domain\UserManagement\Events\ExternalHrImportApplied;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class ExternalHrImportAggregate extends AggregateRoot
{
    public function applyImport(array $rows, string $actor): self
    {
        $this->recordThat(new ExternalHrImportApplied($rows, $actor));

        return $this;
    }
}
