<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 打刻ログ (docs/03-architecture.md 3.3)。勤怠の正ではなく参考情報。
 * 同一user_id・work_dateの打刻群がUC-A012の意味で整合している場合のみ
 * attendance_days / attendance_breaks に反映される。
 */
#[Fillable(['user_id', 'work_date', 'punch_type', 'punched_at', 'source', 'note'])]
class AttendancePunch extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'punched_at' => 'datetime',
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
