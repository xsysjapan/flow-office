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
    'attendance_day_id', 'planned_work_minutes', 'work_minutes', 'deemed_work_minutes',
    'payroll_work_minutes', 'prescribed_work_minutes',
    'statutory_within_overtime_minutes', 'statutory_excess_overtime_minutes', 'late_night_work_minutes',
    'late_night_prescribed_work_minutes', 'late_night_statutory_within_overtime_minutes',
    'late_night_statutory_excess_overtime_minutes',
    'legal_holiday_work_minutes', 'prescribed_holiday_work_minutes', 'late_night_legal_holiday_work_minutes',
    'core_time_violation',
    'is_manually_adjusted', 'adjusted_by_user_id', 'adjusted_at',
])]
class AttendanceDailyCalculation extends Model
{
    protected function casts(): array
    {
        return [
            'core_time_violation' => 'boolean',
            'is_manually_adjusted' => 'boolean',
            'adjusted_at' => 'datetime',
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
