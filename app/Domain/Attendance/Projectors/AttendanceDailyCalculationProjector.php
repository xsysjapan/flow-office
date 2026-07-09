<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\AttendanceDailyCalculation;
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
        return ['attendance.day_calculated'];
    }

    public function project(StoredEvent $event): void
    {
        $payload = $event->payload;
        $attendanceDayId = $payload['attendance_day_id'];

        AttendanceDailyCalculation::query()->updateOrCreate(
            ['attendance_day_id' => $attendanceDayId],
            [
                'planned_work_minutes' => $payload['planned_work_minutes'],
                'actual_work_minutes' => $payload['actual_work_minutes'],
                'prescribed_work_minutes' => $payload['prescribed_work_minutes'],
                'non_statutory_overtime_minutes' => $payload['non_statutory_overtime_minutes'],
                'statutory_overtime_minutes' => $payload['statutory_overtime_minutes'],
                'late_night_minutes' => $payload['late_night_minutes'],
                'legal_holiday_work_minutes' => $payload['legal_holiday_work_minutes'],
                'company_holiday_work_minutes' => $payload['company_holiday_work_minutes'],
                'legal_holiday_late_night_minutes' => $payload['legal_holiday_late_night_minutes'],
            ],
        );
    }

    public function reset(): void
    {
        DB::table('attendance_daily_calculations')->truncate();
    }
}
