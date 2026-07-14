<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * employee_shift.assigned。社員別勤務予定(employee_shift_assignments)への割当・更新の履歴。
 * カレンダー基準の一括生成(UC-C003)と、3交代制のシフトパターン日別割当(UC-C004)の
 * どちらからも発生する(`shiftPatternId`が設定されているかどうかで判別できる)。
 */
class EmployeeShiftAssigned implements DomainEvent
{
    public function __construct(
        public readonly int $employeeShiftAssignmentId,
        public readonly int $userId,
        public readonly string $workDate,
        public readonly ?int $workStyleId,
        public readonly ?int $shiftPatternId,
        public readonly string $dayType,
        public readonly bool $isWorkingDay,
        public readonly bool $isLegalHoliday,
        public readonly bool $isCompanyHoliday,
        public readonly ?string $plannedStartAt,
        public readonly ?string $plannedEndAt,
        public readonly int $plannedBreakMinutes,
        public readonly ?string $plannedBreakStartAt,
        public readonly ?string $plannedBreakEndAt,
        public readonly int $assignedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'employee_shift.assigned';
    }

    public function payload(): array
    {
        return [
            'employee_shift_assignment_id' => $this->employeeShiftAssignmentId,
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'work_style_id' => $this->workStyleId,
            'shift_pattern_id' => $this->shiftPatternId,
            'day_type' => $this->dayType,
            'is_working_day' => $this->isWorkingDay,
            'is_legal_holiday' => $this->isLegalHoliday,
            'is_company_holiday' => $this->isCompanyHoliday,
            'planned_start_at' => $this->plannedStartAt,
            'planned_end_at' => $this->plannedEndAt,
            'planned_break_minutes' => $this->plannedBreakMinutes,
            'planned_break_start_at' => $this->plannedBreakStartAt,
            'planned_break_end_at' => $this->plannedBreakEndAt,
            'assigned_by_user_id' => $this->assignedByUserId,
        ];
    }
}
