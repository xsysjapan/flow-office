<?php

namespace App\Domain\ReadModelRebuild\Aggregates;

use App\Domain\ReadModelRebuild\Events\ReadModelRebuildFinished;
use App\Domain\ReadModelRebuild\Events\ReadModelRebuildRequested;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/** ReadModelの作り直しの依頼・終了を`stored_events`へ残す監査専用の集約(Projectorは無い。監査ログ画面が`stored_events`を読む)。 */
class ReadModelRebuildAuditAggregate extends AggregateRoot
{
    public static function aggregateId(): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'read-model-rebuild')->toString();
    }

    public function recordRequested(string $runId, string $requestedByUserId, bool $dryRun): self
    {
        $this->recordThat(new ReadModelRebuildRequested($runId, $requestedByUserId, $dryRun));

        return $this;
    }

    public function recordFinished(string $runId, string $result, bool $dryRun, int $changedTables): self
    {
        $this->recordThat(new ReadModelRebuildFinished($runId, $result, $dryRun, $changedTables));

        return $this;
    }
}
