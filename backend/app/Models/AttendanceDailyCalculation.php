<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection: attendance.day_calculated イベントから再生成可能。
 * .claude/skills/attendance-calc-review 参照。
 */
#[Fillable([
    'attendance_day_id', 'planned_work_minutes', 'actual_work_minutes', 'deemed_work_minutes',
    'payroll_work_minutes', 'prescribed_work_minutes',
    'non_statutory_overtime_minutes', 'statutory_overtime_minutes', 'late_night_minutes',
    'legal_holiday_work_minutes', 'company_holiday_work_minutes', 'legal_holiday_late_night_minutes',
    'core_time_violation',
])]
class AttendanceDailyCalculation extends Model
{
    protected function casts(): array
    {
        return [
            'core_time_violation' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AttendanceDay, $this>
     */
    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class);
    }
}
