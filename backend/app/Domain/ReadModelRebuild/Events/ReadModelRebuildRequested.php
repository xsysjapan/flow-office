<?php

namespace App\Domain\ReadModelRebuild\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** ReadModelの作り直し(`read-models:rebuild`)の依頼の監査イベント。 */
class ReadModelRebuildRequested extends ShouldBeStored
{
    public function __construct(public readonly string $runId, public readonly string $requestedByUserId, public readonly bool $dryRun) {}
}
