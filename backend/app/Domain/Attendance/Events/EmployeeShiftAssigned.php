<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * employee_shift.assigned。社員別勤務予定(employee_shift_assignments)への割当・更新の履歴。
 * カレンダー基準の一括生成(UC-C003)と、3交代制のシフトパターン日別割当(UC-C004)の
 * どちらからも発生する(`shiftPatternId`が設定されているかどうかで判別できる)。
 * 集約ID(employee_shift_assignments.id)は`aggregateRootUuid()`から取得する。
 *
 * `isPublished`/`isManuallyOverridden`はEmployeeShiftAssignmentProjectorが行を完全に
 * 再構築できるよう明示的に持たせる(DBのデフォルト値やイベント間での「触れないので前の値を
 * 維持する」という暗黙の挙動に依存しない。enrich eventsパターン)。
 */
class EmployeeShiftAssigned extends ShouldBeStored
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
    ) {}
}
