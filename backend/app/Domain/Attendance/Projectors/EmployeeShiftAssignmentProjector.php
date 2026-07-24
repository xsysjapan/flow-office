<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\Attendance\Events\EmployeeShiftPlanChanged;
use App\Domain\Attendance\Events\EmployeeShiftPublished;
use App\Models\EmployeeShiftAssignment;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * employee_shift.*イベントからemployee_shift_assignmentsを作成・更新する
 * (.claude/skills/add-projection参照)。datetime系のフィールドはモデルのcastを経由させる
 * ため、生のupdate()ではなくfill()+save()で反映する(planned_start_at等はISO8601文字列で
 * イベントに保持されており、Carbon解釈をモデルのcastに委ねる)。
 */
class EmployeeShiftAssignmentProjector extends Projector
{
    public function onEmployeeShiftAssigned(EmployeeShiftAssigned $event): void
    {
        $assignment = EmployeeShiftAssignment::query()->find($event->aggregateRootUuid())
            ?? new EmployeeShiftAssignment(['id' => $event->aggregateRootUuid()]);

        $assignment->fill([
            'user_id' => $event->userId,
            'work_date' => $event->workDate,
            'work_style_id' => $event->workStyleId,
            'shift_pattern_id' => $event->shiftPatternId,
            'day_type' => $event->dayType,
            'is_working_day' => $event->isWorkingDay,
            'is_legal_holiday' => $event->isLegalHoliday,
            'is_company_holiday' => $event->isCompanyHoliday,
            'planned_start_at' => $event->plannedStartAt,
            'planned_end_at' => $event->plannedEndAt,
            'planned_break_minutes' => $event->plannedBreakMinutes,
            'planned_break_start_at' => $event->plannedBreakStartAt,
            'planned_break_end_at' => $event->plannedBreakEndAt,
            'is_published' => $event->isPublished,
            'is_manually_overridden' => $event->isManuallyOverridden,
        ])->save();
    }

    public function onEmployeeShiftPlanChanged(EmployeeShiftPlanChanged $event): void
    {
        $assignment = EmployeeShiftAssignment::query()->findOrFail($event->aggregateRootUuid());

        $assignment->fill([
            'planned_start_at' => $event->plannedStartAt,
            'planned_end_at' => $event->plannedEndAt,
            'planned_break_minutes' => $event->plannedBreakMinutes,
        ])->save();
    }

    public function onEmployeeShiftPublished(EmployeeShiftPublished $event): void
    {
        $assignment = EmployeeShiftAssignment::query()->find($event->aggregateRootUuid());

        if ($assignment === null) {
            return;
        }

        $assignment->is_published = true;
        $assignment->save();
    }
}
