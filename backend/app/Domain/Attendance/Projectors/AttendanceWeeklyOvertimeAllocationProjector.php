<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceWeeklyOvertimeAllocated;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AttendanceWeeklyOvertimeAllocationProjector extends Projector
{
    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        AttendanceWeeklyOvertimeAllocation::query()->where('attendance_day_id', $event->aggregateRootUuid())->delete();
    }

    public function onAttendanceWeeklyOvertimeAllocated(AttendanceWeeklyOvertimeAllocated $event): void
    {
        $attendanceDayId = $event->aggregateRootUuid();
        $previous = AttendanceWeeklyOvertimeAllocation::query()->where('attendance_day_id', $attendanceDayId)->first();
        $calculation = AttendanceDailyCalculation::query()->where('attendance_day_id', $attendanceDayId)->firstOrFail();
        $previousPrescribed = (int) ($previous?->prescribed_minutes ?? 0);
        $previousNonPrescribed = (int) ($previous?->non_prescribed_minutes ?? 0);
        $previousLateNightPrescribed = (int) ($previous?->late_night_prescribed_minutes ?? 0);
        $previousLateNightNonPrescribed = (int) ($previous?->late_night_non_prescribed_minutes ?? 0);
        $previousTotal = $previousPrescribed + $previousNonPrescribed;
        $newTotal = $event->prescribedMinutes + $event->nonPrescribedMinutes;
        $previousLateNightTotal = $previousLateNightPrescribed + $previousLateNightNonPrescribed;
        $newLateNightTotal = $event->lateNightPrescribedMinutes + $event->lateNightNonPrescribedMinutes;

        $calculation->update([
            // 旧3区分を参照する既存画面・Excel・汎用/freee CSVとの互換値も同期する。
            'statutory_within_overtime_minutes' => $calculation->statutory_within_overtime_minutes + $previousNonPrescribed - $event->nonPrescribedMinutes,
            'statutory_excess_overtime_minutes' => $calculation->statutory_excess_overtime_minutes - $previousTotal + $newTotal,
            'late_night_prescribed_work_minutes' => $calculation->late_night_prescribed_work_minutes + $previousLateNightPrescribed - $event->lateNightPrescribedMinutes,
            'late_night_statutory_within_overtime_minutes' => $calculation->late_night_statutory_within_overtime_minutes + $previousLateNightNonPrescribed - $event->lateNightNonPrescribedMinutes,
            'late_night_statutory_excess_overtime_minutes' => $calculation->late_night_statutory_excess_overtime_minutes - $previousLateNightTotal + $newLateNightTotal,
            'prescribed_statutory_within_work_minutes' => $calculation->prescribed_statutory_within_work_minutes + $previousPrescribed - $event->prescribedMinutes,
            'non_prescribed_statutory_within_work_minutes' => $calculation->non_prescribed_statutory_within_work_minutes + $previousNonPrescribed - $event->nonPrescribedMinutes,
            'prescribed_statutory_excess_work_minutes' => $calculation->prescribed_statutory_excess_work_minutes - $previousPrescribed + $event->prescribedMinutes,
            'non_prescribed_statutory_excess_work_minutes' => $calculation->non_prescribed_statutory_excess_work_minutes - $previousNonPrescribed + $event->nonPrescribedMinutes,
            'late_night_prescribed_statutory_within_work_minutes' => $calculation->late_night_prescribed_statutory_within_work_minutes + $previousLateNightPrescribed - $event->lateNightPrescribedMinutes,
            'late_night_non_prescribed_statutory_within_work_minutes' => $calculation->late_night_non_prescribed_statutory_within_work_minutes + $previousLateNightNonPrescribed - $event->lateNightNonPrescribedMinutes,
            'late_night_prescribed_statutory_excess_work_minutes' => $calculation->late_night_prescribed_statutory_excess_work_minutes - $previousLateNightPrescribed + $event->lateNightPrescribedMinutes,
            'late_night_non_prescribed_statutory_excess_work_minutes' => $calculation->late_night_non_prescribed_statutory_excess_work_minutes - $previousLateNightNonPrescribed + $event->lateNightNonPrescribedMinutes,
        ]);

        AttendanceWeeklyOvertimeAllocation::query()->updateOrCreate(
            ['attendance_day_id' => $attendanceDayId],
            [
                'week_start_date' => $event->weekStartDate,
                'prescribed_minutes' => $event->prescribedMinutes,
                'non_prescribed_minutes' => $event->nonPrescribedMinutes,
                'late_night_prescribed_minutes' => $event->lateNightPrescribedMinutes,
                'late_night_non_prescribed_minutes' => $event->lateNightNonPrescribedMinutes,
                'allocated_by_user_id' => $event->allocatedByUserId,
            ],
        );
    }
}
