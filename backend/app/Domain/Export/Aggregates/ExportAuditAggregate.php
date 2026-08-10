<?php

namespace App\Domain\Export\Aggregates;

use App\Domain\Export\Events\ExportCreated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/** An export is an immutable audit stream with exactly one creation fact. */
final class ExportAuditAggregate extends AggregateRoot
{
    /** @param array<string, mixed> $params */
    public function record(string $exportType, array $params, string $requestedByUserId, int $rowCount): self
    {
        $this->recordThat(new ExportCreated($exportType, $params, $requestedByUserId, $rowCount));

        return $this;
    }
}
