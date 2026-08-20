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
        $attendanceDay = AttendanceDay::query()->find($attendanceDayId);
        if ($attendanceDay === null) {
            return;
        }

        $payload = $event->calculation;
        $legacyPrescribedWithin = max(0,
            (int) ($payload['work_minutes'] ?? 0)
            - (int) ($payload['statutory_within_overtime_minutes'] ?? 0)
            - (int) ($payload['statutory_excess_overtime_minutes'] ?? 0)
            - (int) ($payload['legal_holiday_work_minutes'] ?? 0)
            - (int) ($payload['prescribed_holiday_work_minutes'] ?? 0),
        );
        $legacyNonPrescribedWithin = (int) (($payload['prescribed_holiday_work_minutes'] ?? 0) > 0
            ? $payload['prescribed_holiday_work_minutes']
            : ($payload['statutory_within_overtime_minutes'] ?? 0));

        // day_classification(working_day/prescribed_holiday/legal_holiday)は
        // attendance_daily_calculationsではなくattendance_days側の派生列だが、
        // AttendanceCalculatorが計算と同時に判定するためこのイベントのpayloadに含まれる。
        // 古いイベント(この項目が追加される前)の再生時はキーが無いため既存値を維持する。
        if (array_key_exists('day_classification', $payload)) {
            $attendanceDay->day_classification = $payload['day_classification'];
            $attendanceDay->save();
        }

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
                'prescribed_statutory_within_work_minutes' => $payload['prescribed_statutory_within_work_minutes'] ?? $legacyPrescribedWithin,
                'non_prescribed_statutory_within_work_minutes' => $payload['non_prescribed_statutory_within_work_minutes'] ?? $legacyNonPrescribedWithin,
                'prescribed_statutory_excess_work_minutes' => $payload['prescribed_statutory_excess_work_minutes'] ?? 0,
                'non_prescribed_statutory_excess_work_minutes' => $payload['non_prescribed_statutory_excess_work_minutes'] ?? $payload['statutory_excess_overtime_minutes'],
                'late_night_work_minutes' => $payload['late_night_work_minutes'],
                'late_night_prescribed_work_minutes' => $payload['late_night_prescribed_work_minutes'] ?? 0,
                'late_night_statutory_within_overtime_minutes' => $payload['late_night_statutory_within_overtime_minutes'] ?? 0,
                'late_night_statutory_excess_overtime_minutes' => $payload['late_night_statutory_excess_overtime_minutes'] ?? 0,
                'late_night_prescribed_statutory_within_work_minutes' => $payload['late_night_prescribed_statutory_within_work_minutes'] ?? ($payload['late_night_prescribed_work_minutes'] ?? 0),
                'late_night_non_prescribed_statutory_within_work_minutes' => $payload['late_night_non_prescribed_statutory_within_work_minutes'] ?? ($payload['late_night_statutory_within_overtime_minutes'] ?? 0),
                'late_night_prescribed_statutory_excess_work_minutes' => $payload['late_night_prescribed_statutory_excess_work_minutes'] ?? 0,
                'late_night_non_prescribed_statutory_excess_work_minutes' => $payload['late_night_non_prescribed_statutory_excess_work_minutes'] ?? ($payload['late_night_statutory_excess_overtime_minutes'] ?? 0),
                'legal_holiday_work_minutes' => $payload['legal_holiday_work_minutes'],
                'prescribed_holiday_work_minutes' => $payload['prescribed_holiday_work_minutes'],
                'late_night_legal_holiday_work_minutes' => $payload['late_night_legal_holiday_work_minutes'],
                'late_night_prescribed_holiday_work_minutes' => $payload['late_night_prescribed_holiday_work_minutes'] ?? 0,
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

        $existing = AttendanceDay::query()->whereKey($attendanceDayId)->first();

        if ($existing === null) {
            return;
        }

        // payrollWorkMinutesがnull(この項目が追加される前に記録された古いイベントの再生時のみ
        // 起こりうる)の場合、直前の計算行の値をそのまま保持する。
        $payrollWorkMinutes = $event->payrollWorkMinutes ?? $existing->calculation?->payroll_work_minutes ?? $event->prescribedWorkMinutes;
        // lateNightPrescribedHolidayWorkMinutesがnull(この項目が追加される前に記録された古い
        // イベントの再生時のみ起こりうる)の場合、直前の計算行の値をそのまま保持する。
        $lateNightPrescribedHolidayWorkMinutes = $event->lateNightPrescribedHolidayWorkMinutes
            ?? $existing->calculation?->late_night_prescribed_holiday_work_minutes ?? 0;

        AttendanceDailyCalculation::query()->updateOrCreate(
            ['attendance_day_id' => $attendanceDayId],
            [
                'prescribed_work_minutes' => $event->prescribedWorkMinutes,
                'statutory_within_overtime_minutes' => $event->statutoryWithinOvertimeMinutes,
                'statutory_excess_overtime_minutes' => $event->statutoryExcessOvertimeMinutes,
                // 旧来の手動補正APIは3区分のみを受け取るため、互換変換では所定時間を
                // 所定内法定内、法定内/外残業をそれぞれ所定外へ割り当てる。
                'prescribed_statutory_within_work_minutes' => $event->prescribedWorkMinutes,
                'non_prescribed_statutory_within_work_minutes' => $event->statutoryWithinOvertimeMinutes,
                'prescribed_statutory_excess_work_minutes' => 0,
                'non_prescribed_statutory_excess_work_minutes' => $event->statutoryExcessOvertimeMinutes,
                'late_night_work_minutes' => $event->lateNightPrescribedWorkMinutes
                    + $event->lateNightStatutoryWithinOvertimeMinutes
                    + $event->lateNightStatutoryExcessOvertimeMinutes
                    + $event->lateNightLegalHolidayWorkMinutes
                    + $lateNightPrescribedHolidayWorkMinutes,
                'late_night_prescribed_work_minutes' => $event->lateNightPrescribedWorkMinutes,
                'late_night_statutory_within_overtime_minutes' => $event->lateNightStatutoryWithinOvertimeMinutes,
                'late_night_statutory_excess_overtime_minutes' => $event->lateNightStatutoryExcessOvertimeMinutes,
                'late_night_prescribed_statutory_within_work_minutes' => $event->lateNightPrescribedWorkMinutes,
                'late_night_non_prescribed_statutory_within_work_minutes' => $event->lateNightStatutoryWithinOvertimeMinutes,
                'late_night_prescribed_statutory_excess_work_minutes' => 0,
                'late_night_non_prescribed_statutory_excess_work_minutes' => $event->lateNightStatutoryExcessOvertimeMinutes,
                'legal_holiday_work_minutes' => $event->legalHolidayWorkMinutes,
                'prescribed_holiday_work_minutes' => $event->prescribedHolidayWorkMinutes,
                'payroll_work_minutes' => $payrollWorkMinutes,
                'late_night_legal_holiday_work_minutes' => $event->lateNightLegalHolidayWorkMinutes,
                'late_night_prescribed_holiday_work_minutes' => $lateNightPrescribedHolidayWorkMinutes,
                'is_manually_adjusted' => true,
                'adjusted_by_user_id' => $event->adjustedByUserId,
                'adjusted_at' => $event->createdAt(),
            ],
        );
    }
}
