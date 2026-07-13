<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 社員ごとのローテーション基準(指示書 8.5節)。rotation_start_dateの時点で
 * rotation_start_position番目(0始まり)のパターンが適用されているものとして、
 * 以降の日別勤務予定を機械的に算出する。
 */
#[Fillable(['user_id', 'rotation_pattern_id', 'rotation_start_date', 'rotation_start_position', 'assigned_by_user_id'])]
class EmployeeRotationAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'rotation_start_date' => 'date',
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
     * @return BelongsTo<RotationPattern, $this>
     */
    public function rotationPattern(): BelongsTo
    {
        return $this->belongsTo(RotationPattern::class);
    }

    /**
     * 指定日がローテーション周期の何番目(0始まり)にあたるかを返す。
     */
    public function sequenceIndexFor(Carbon $date, int $cycleLength): int
    {
        $daysSinceStart = $this->rotation_start_date->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
        $index = ($this->rotation_start_position + $daysSinceStart) % $cycleLength;

        return $index < 0 ? $index + $cycleLength : $index;
    }
}
