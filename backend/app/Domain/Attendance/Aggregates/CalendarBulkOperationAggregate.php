<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\CalendarBulkOperationApplied;
use App\Domain\Attendance\Events\CalendarBulkOperationReverted;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * calendar_bulk_operation集約 (UC-C013)。
 */
class CalendarBulkOperationAggregate extends AggregateRoot
{
    /**
     * @param  array<string, mixed>  $targetScope
     * @param  list<array{user_id: string, work_date: string, employee_calendar_entry_id: ?string, result: string, error_code: ?string, previous_snapshot: ?array<string, mixed>}>  $targets
     */
    public function applyOperation(
        string $operationType,
        array $targetScope,
        string $conflictPolicy,
        string $reason,
        array $targets,
        string $requestedByUserId,
    ): self {
        $this->recordThat(new CalendarBulkOperationApplied(
            operationType: $operationType,
            targetScope: $targetScope,
            conflictPolicy: $conflictPolicy,
            reason: $reason,
            targets: $targets,
            requestedByUserId: $requestedByUserId,
        ));

        return $this;
    }

    /**
     * @param  list<string>  $revertedTargetIds
     * @param  list<string>  $excludedTargetIds
     */
    public function revert(array $revertedTargetIds, array $excludedTargetIds, string $revertedByUserId): self
    {
        $this->recordThat(new CalendarBulkOperationReverted(
            revertedTargetIds: $revertedTargetIds,
            excludedTargetIds: $excludedTargetIds,
            revertedByUserId: $revertedByUserId,
        ));

        return $this;
    }
}
