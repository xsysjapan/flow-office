<?php

namespace App\Domain\ReadModelRebuild\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** ReadModelの作り直しの終了の監査イベント。`result`は`succeeded`または`failed`。 */
class ReadModelRebuildFinished extends ShouldBeStored
{
    public function __construct(public readonly string $runId, public readonly string $result, public readonly bool $dryRun, public readonly int $changedTables) {}
}
