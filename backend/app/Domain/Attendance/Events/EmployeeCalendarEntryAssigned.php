<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * employee_calendar_entry.assigned。社員別勤務予定(employee_calendar_entries)への割当・更新の履歴。
 * カレンダー基準の一括生成(UC-C003)と、3交代制のシフトパターン日別割当(UC-C004)の
 * どちらからも発生する(`shiftPatternId`が設定されているかどうかで判別できる)。
 * 集約ID(employee_calendar_entries.id)は`aggregateRootUuid()`から取得する。
 *
 * `isPublished`/`isManuallyOverridden`はEmployeeCalendarEntryProjectorが行を完全に
 * 再構築できるよう明示的に持たせる(DBのデフォルト値やイベント間での「触れないので前の値を
 * 維持する」という暗黙の挙動に依存しない。enrich eventsパターン)。
 */
class EmployeeCalendarEntryAssigned extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $workStyleId,
        public readonly ?string $shiftPatternId,
        public readonly string $dayType,
        public readonly bool $isWorkingDay,
        public readonly bool $isLegalHoliday,
        public readonly bool $isCompanyHoliday,
        public readonly ?string $plannedStartAt,
        public readonly ?string $plannedEndAt,
        public readonly int $plannedBreakMinutes,
        public readonly ?string $plannedBreakStartAt,
        public readonly ?string $plannedBreakEndAt,
        public readonly bool $isPublished,
        public readonly bool $isManuallyOverridden,
        public readonly string $assignedByUserId,
        // UC-C013: 一括操作(calendar_bulk_operations)経由の行を特定するための追加フィールド。
        // 既存呼び出し元(UC-C003カレンダー基準一括生成・UC-C008ローテーション一括生成)は
        // これらを渡さないため、既定値(schedule_stateはnull=Projector側でUNASSIGNED相当に
        // フォールバック)のままで挙動は変わらない。
        public readonly ?string $scheduleState = null,
        public readonly ?string $entryType = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $bulkOperationId = null,
    ) {}
}
