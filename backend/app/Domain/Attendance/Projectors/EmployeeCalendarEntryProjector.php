<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\EmployeeCalendarEntryAssigned;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPlanChanged;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPublished;
use App\Models\EmployeeCalendarEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * employee_shift.*イベントからemployee_calendar_entriesを作成・更新する
 * (.claude/skills/add-projection参照)。datetime系のフィールドはモデルのcastを経由させる
 * ため、生のupdate()ではなくfill()+save()で反映する(planned_start_at等はISO8601文字列で
 * イベントに保持されており、Carbon解釈をモデルのcastに委ねる)。
 */
class EmployeeCalendarEntryProjector extends Projector
{
    public function onEmployeeCalendarEntryAssigned(EmployeeCalendarEntryAssigned $event): void
    {
        $assignment = EmployeeCalendarEntry::query()->find($event->aggregateRootUuid())
            ?? new EmployeeCalendarEntry(['id' => $event->aggregateRootUuid()]);

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

    public function onEmployeeCalendarEntryPlanChanged(EmployeeCalendarEntryPlanChanged $event): void
    {
        $assignment = EmployeeCalendarEntry::query()->findOrFail($event->aggregateRootUuid());

        $assignment->fill([
            'planned_start_at' => $event->plannedStartAt,
            'planned_end_at' => $event->plannedEndAt,
            'planned_break_minutes' => $event->plannedBreakMinutes,
        ])->save();
    }

    public function onEmployeeCalendarEntryPublished(EmployeeCalendarEntryPublished $event): void
    {
        $assignment = EmployeeCalendarEntry::query()->find($event->aggregateRootUuid());

        if ($assignment === null) {
            return;
        }

        $assignment->is_published = true;
        $assignment->save();
    }
}
