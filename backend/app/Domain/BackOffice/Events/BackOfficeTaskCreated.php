<?php

namespace App\Domain\BackOffice\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * backoffice_task.created。BackOfficeTaskProjectorが集約UUID(aggregateRootUuid() =
 * backoffice_tasks.id)をキーに行を新規作成する。
 */
class BackOfficeTaskCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $taskType,
        public readonly string $title,
        public readonly ?string $assignedDepartment,
        public readonly ?string $dueOn,
    ) {}
}
