<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\StoredEvent;
use Illuminate\Support\Facades\DB;

/**
 * attendance.day_calculated イベントから attendance_daily_calculations を再生成する。
 * (.claude/skills/add-projection 参照)
 */
class AttendanceDailyCalculationProjector implements Projector
{
    public function eventTypes(): array
    {
        return ['attendance.day_calculated', 'attendance.daily_calculation_adjusted'];
    }

    public function project(StoredEvent $event): void
    {
        $payload = $this->normalizeLegacyPayload($event->payload);
        $attendanceDayId = $payload['attendance_day_id'];

        // UC-A015で日次勤怠(attendance_days)自体が削除されている場合、そのIDを参照する
        // 過去のイベントは再生成時にスキップする(親行が無い状態でupdateOrCreateすると
        // 外部キー制約違反になるため)。
        if (! AttendanceDay::query()->whereKey($attendanceDayId)->exists()) {
            return;
        }

        if ($event->event_type === 'attendance.daily_calculation_adjusted') {
            // 手動補正。実績が再編集され attendance.day_calculated が再発生すると解除される。
            AttendanceDailyCalculation::query()->updateOrCreate(
                ['attendance_day_id' => $attendanceDayId],
                [
                    'prescribed_work_minutes' => $payload['prescribed_work_minutes'],
                    'statutory_within_overtime_minutes' => $payload['statutory_within_overtime_minutes'],
                    'statutory_excess_overtime_minutes' => $payload['statutory_excess_overtime_minutes'],
                    'late_night_work_minutes' => $payload['late_night_work_minutes'],
                    'late_night_prescribed_work_minutes' => $payload['late_night_prescribed_work_minutes'] ?? 0,
                    'late_night_statutory_within_overtime_minutes' => $payload['late_night_statutory_within_overtime_minutes'] ?? 0,
                    'late_night_statutory_excess_overtime_minutes' => $payload['late_night_statutory_excess_overtime_minutes'] ?? 0,
                    'legal_holiday_work_minutes' => $payload['legal_holiday_work_minutes'],
                    'prescribed_holiday_work_minutes' => $payload['prescribed_holiday_work_minutes'],
                    'late_night_legal_holiday_work_minutes' => $payload['late_night_legal_holiday_work_minutes'] ?? 0,
                    'is_manually_adjusted' => true,
                    'adjusted_by_user_id' => $payload['adjusted_by_user_id'],
                    'adjusted_at' => $event->occurred_at,
                ],
            );

            return;
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
                // 実績の再編集による再計算は、直前の手動補正を解除する(再計算結果が最新の正)。
                'is_manually_adjusted' => false,
                'adjusted_by_user_id' => null,
                'adjusted_at' => null,
            ],
        );
    }

    public function reset(): void
    {
        DB::table('attendance_daily_calculations')->truncate();
    }

    /**
     * 物理名変更前に保存されたイベントも再生できるよう、新しいpayloadキーへ寄せる。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeLegacyPayload(array $payload): array
    {
        $renamedKeys = [
            'actual_work_minutes' => 'work_minutes',
            'non_statutory_overtime_minutes' => 'statutory_within_overtime_minutes',
            'statutory_overtime_minutes' => 'statutory_excess_overtime_minutes',
            'late_night_minutes' => 'late_night_work_minutes',
            'regular_work_late_night_minutes' => 'late_night_prescribed_work_minutes',
            'non_statutory_overtime_late_night_minutes' => 'late_night_statutory_within_overtime_minutes',
            'statutory_overtime_late_night_minutes' => 'late_night_statutory_excess_overtime_minutes',
            'company_holiday_work_minutes' => 'prescribed_holiday_work_minutes',
            'legal_holiday_late_night_minutes' => 'late_night_legal_holiday_work_minutes',
        ];

        foreach ($renamedKeys as $legacyKey => $newKey) {
            if (! array_key_exists($newKey, $payload) && array_key_exists($legacyKey, $payload)) {
                $payload[$newKey] = $payload[$legacyKey];
            }
        }

        return $payload;
    }
}
