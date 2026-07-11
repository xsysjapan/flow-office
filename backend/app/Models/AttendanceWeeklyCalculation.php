<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection: 週次の実働・残業集計。attendance.day_calculated イベントから再生成可能。
 * .claude/skills/attendance-calc-review 参照。
 *
 * 日8時間超の残業は attendance_daily_calculations.statutory_overtime_minutes で
 * 既に計上済みのため、weekly_statutory_overtime_minutes はそれを除いた「日8時間以内の
 * 実働」を週単位で合計し、週40時間を超えた分のみを表す(二重計上しない)。
 * 月次の法定時間外合計は daily_statutory_overtime_minutes(日次分) +
 * weekly_statutory_overtime_minutes(週次分) の合計になる。
 */
#[Fillable([
    'user_id', 'week_start_date', 'week_end_date', 'actual_work_minutes',
    'daily_statutory_overtime_minutes', 'weekly_statutory_overtime_minutes', 'legal_holiday_work_minutes',
])]
class AttendanceWeeklyCalculation extends Model
{
    protected function casts(): array
    {
        return [
            'week_start_date' => 'date',
            'week_end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
