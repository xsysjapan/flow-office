<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance_day.calculated / attendance_day.daily_calculation_adjusted イベントから
 * attendance_daily_calculations を再生成する(.claude/skills/add-projection 参照)。
 *
 * spatie/laravel-event-sourcing移行(docs/29-event-sourcing-framework-migration.md)により
 * AttendanceDayAggregateのイベントがShouldBeStoredになったため、旧イベントバス
 * (config('domain.projectors')経由)ではなくこちらのspatie Projectorが購読する。
 */
class AttendanceDailyCalculationProjector extends Projector
{
    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        $attendanceDayId = $event->aggregateRootUuid();

        // UC-A015で日次勤怠(attendance_days)自体が削除されている場合、そのIDを参照する
        // 過去のイベントは再生成時にスキップする(親行が無い状態でupdateOrCreateすると
        // 外部キー制約違反になるため)。
        if (! AttendanceDay::query()->whereKey($attendanceDayId)->exists()) {
            return;
        }

        $payload = $event->calculation;

        AttendanceDailyCalculation::query()->updateOrCreate(
            ['attendance_day_id' => $attendanceDayId],
            [
                'planned_work_minutes' => $payload['planned_work_minutes'],
                'work_minutes' => $payload['work_minutes'],
                'deemed_work_minutes' => $payload['deemed_work_minutes'],
                'payroll_work_minutes' => $payload['payroll_work_minutes'],
                'prescribed_work_minutes' => $payload['prescribed_work_minutes'],
                'statutory_within_overtime_minutes' => $payload['statutory_within_overtime_minutes'],
                'statutory_excess_overtime_minutes' => $payload['statutory_excess_overtime_minutes'],
                'late_night_work_minutes' => $payload['late_night_work_minutes'],
                'late_night_prescribed_work_minutes' => $payload['late_night_prescribed_work_minutes'] ?? 0,
                'late_night_statutory_within_overtime_minutes' => $payload['late_night_statutory_within_overtime_minutes'] ?? 0,
                'late_night_statutory_excess_overtime_minutes' => $payload['late_night_statutory_excess_overtime_minutes'] ?? 0,
                'legal_holiday_work_minutes' => $payload['legal_holiday_work_minutes'],
                'prescribed_holiday_work_minutes' => $payload['prescribed_holiday_work_minutes'],
                'late_night_legal_holiday_work_minutes' => $payload['late_night_legal_holiday_work_minutes'],
                'core_time_violation' => $payload['core_time_violation'] ?? false,
                'absence_minutes' => $payload['absence_minutes'] ?? 0,
                'special_leave_minutes' => $payload['special_leave_minutes'] ?? 0,
                'paid_leave_days' => $payload['paid_leave_days'] ?? 0,
                'paid_leave_minutes' => $payload['paid_leave_minutes'] ?? 0,
                'special_leave_days' => $payload['special_leave_days'] ?? 0,
                // 実績の再編集による再計算は、直前の手動補正を解除する(再計算結果が最新の正)。
                'is_manually_adjusted' => false,
                'adjusted_by_user_id' => null,
                'adjusted_at' => null,
            ],
        );
    }

    public function onAttendanceDailyCalculationAdjusted(AttendanceDailyCalculationAdjusted $event): void
    {
        $attendanceDayId = $event->aggregateRootUuid();

        if (! AttendanceDay::query()->whereKey($attendanceDayId)->exists()) {
            return;
        }

        AttendanceDailyCalculation::query()->updateOrCreate(
            ['attendance_day_id' => $attendanceDayId],
            [
                'prescribed_work_minutes' => $event->prescribedWorkMinutes,
                'statutory_within_overtime_minutes' => $event->statutoryWithinOvertimeMinutes,
                'statutory_excess_overtime_minutes' => $event->statutoryExcessOvertimeMinutes,
                'late_night_work_minutes' => $event->lateNightPrescribedWorkMinutes
                    + $event->lateNightStatutoryWithinOvertimeMinutes
                    + $event->lateNightStatutoryExcessOvertimeMinutes
                    + $event->lateNightLegalHolidayWorkMinutes,
                'late_night_prescribed_work_minutes' => $event->lateNightPrescribedWorkMinutes,
                'late_night_statutory_within_overtime_minutes' => $event->lateNightStatutoryWithinOvertimeMinutes,
                'late_night_statutory_excess_overtime_minutes' => $event->lateNightStatutoryExcessOvertimeMinutes,
                'legal_holiday_work_minutes' => $event->legalHolidayWorkMinutes,
                'prescribed_holiday_work_minutes' => $event->prescribedHolidayWorkMinutes,
                'late_night_legal_holiday_work_minutes' => $event->lateNightLegalHolidayWorkMinutes,
                'is_manually_adjusted' => true,
                'adjusted_by_user_id' => $event->adjustedByUserId,
                'adjusted_at' => $event->createdAt(),
            ],
        );
    }
}
