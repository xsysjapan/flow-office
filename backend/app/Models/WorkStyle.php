<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤務形態 (docs/08-usecases-calendar-shift.md UC-C002)。
 * 所定労働時間・残業計算の基準となるため、ここをマスタ化しハードコードしない。
 */
#[Fillable(['code', 'name', 'work_time_system', 'prescribed_daily_minutes', 'prescribed_weekly_minutes', 'default_start_time', 'default_end_time', 'default_break_minutes', 'calendar_id', 'is_shift_based'])]
class WorkStyle extends Model
{
    protected function casts(): array
    {
        return [
            'is_shift_based' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'calendar_id');
    }
}
