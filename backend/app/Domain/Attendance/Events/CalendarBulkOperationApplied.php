<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * calendar_bulk_operation.applied (UC-C013 手順3〜5: 複数従業員予定の一括操作を確定適用する)。
 * 集約ID(calendar_bulk_operations.id)は`aggregateRootUuid()`から取得する。
 *
 * `targets`には対象ごとの適用結果・上書き前の内容(previous_snapshot)をまとめて持たせ、
 * `CalendarBulkOperationProjector`が`calendar_bulk_operations`/`calendar_bulk_operation_targets`
 * を完全に再構築できるようにする。実際の`employee_calendar_entries`への反映は
 * `EmployeeCalendarEntryAggregate::assign`が別途行う(このイベント自体は反映しない)。
 */
class CalendarBulkOperationApplied extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $targetScope
     * @param  list<array{user_id: string, work_date: string, employee_calendar_entry_id: ?string, result: string, error_code: ?string, previous_snapshot: ?array<string, mixed>}>  $targets
     */
    public function __construct(
        public readonly string $operationType,
        public readonly array $targetScope,
        public readonly string $conflictPolicy,
        public readonly string $reason,
        public readonly array $targets,
        public readonly string $requestedByUserId,
    ) {}
}
