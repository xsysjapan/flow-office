<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * employee_shift.plan_changed
 *
 * 勤務予定(所定労働時間)の事後変更の履歴。1か月単位変形労働時間制で、既に発生した
 * 時間外労働をシフトの事後変更で消せないようにするための監査証跡
 * (docs/08-usecases-calendar-shift.md「1か月単位変形労働時間制」参照)。
 */
class EmployeeShiftPlanChanged implements DomainEvent
{
    public function __construct(
        public readonly int $employeeShiftAssignmentId,
        public readonly ?string $previousPlannedStartAt,
        public readonly ?string $previousPlannedEndAt,
        public readonly int $previousPlannedBreakMinutes,
        public readonly ?string $plannedStartAt,
        public readonly ?string $plannedEndAt,
        public readonly int $plannedBreakMinutes,
        public readonly string $reason,
        public readonly string $editedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'employee_shift.plan_changed';
    }

    public function payload(): array
    {
        return [
            'employee_shift_assignment_id' => $this->employeeShiftAssignmentId,
            'previous_planned_start_at' => $this->previousPlannedStartAt,
            'previous_planned_end_at' => $this->previousPlannedEndAt,
            'previous_planned_break_minutes' => $this->previousPlannedBreakMinutes,
            'planned_start_at' => $this->plannedStartAt,
            'planned_end_at' => $this->plannedEndAt,
            'planned_break_minutes' => $this->plannedBreakMinutes,
            'reason' => $this->reason,
            'edited_by_user_id' => $this->editedByUserId,
        ];
    }
}
