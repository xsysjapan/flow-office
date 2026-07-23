<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * employee_shift.plan_changed
 *
 * 勤務予定(所定労働時間)の事後変更の履歴。1か月単位変形労働時間制で、既に発生した
 * 時間外労働をシフトの事後変更で消せないようにするための監査証跡
 * (docs/08-usecases-calendar-shift.md「1か月単位変形労働時間制」参照)。
 */
class EmployeeShiftPlanChanged extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $previousPlannedStartAt,
        public readonly ?string $previousPlannedEndAt,
        public readonly int $previousPlannedBreakMinutes,
        public readonly ?string $plannedStartAt,
        public readonly ?string $plannedEndAt,
        public readonly int $plannedBreakMinutes,
        public readonly string $reason,
        public readonly string $editedByUserId,
    ) {}
}
