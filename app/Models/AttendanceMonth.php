<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 月次勤怠 (docs/07-usecases-attendance.md UC-A007〜UC-A011)。
 * 日次勤怠実績の集計結果であり、直接の入力元にはしない。
 */
#[Fillable(['user_id', 'year_month', 'status', 'approver_user_id', 'submitted_at', 'approved_at', 'returned_at', 'closed_at', 'snapshot_json'])]
class AttendanceMonth extends Model
{
    protected function casts(): array
    {
        return [
            'snapshot_json' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
