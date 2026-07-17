<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤務予定を勤務しなかった時間帯のうち、遅刻・早退等を欠勤時間として処理した区間
 * (docs/03-architecture.md 3.3: 勤怠の正の一つ)。
 */
#[Fillable(['attendance_day_id', 'start_at', 'end_at', 'note'])]
class AttendanceLeaveSegment extends Model
{
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
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
