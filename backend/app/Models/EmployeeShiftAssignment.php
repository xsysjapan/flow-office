<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 社員別勤務予定 (docs/03-architecture.md 3.3: 勤怠の正の一つ)。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'user_id', 'work_date', 'work_style_id', 'shift_pattern_id', 'day_type', 'is_working_day', 'is_legal_holiday', 'is_company_holiday', 'planned_start_at', 'planned_end_at', 'planned_break_minutes', 'planned_break_start_at', 'planned_break_end_at', 'is_published', 'is_manually_overridden'])]
class EmployeeShiftAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_working_day' => 'boolean',
            'is_legal_holiday' => 'boolean',
            'is_company_holiday' => 'boolean',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'planned_break_start_at' => 'datetime',
            'planned_break_end_at' => 'datetime',
            'is_published' => 'boolean',
            'is_manually_overridden' => 'boolean',
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
     * @return BelongsTo<WorkStyle, $this>
     */
    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }

    /**
     * @return BelongsTo<ShiftPattern, $this>
     */
    public function shiftPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class);
    }

    /**
     * あらかじめ設定された所定労働時間(分)。planned_start_at/planned_end_atが
     * 未設定の日は0を返す。
     */
    public function plannedWorkMinutes(): int
    {
        if ($this->planned_start_at === null || $this->planned_end_at === null) {
            return 0;
        }

        return max(0, $this->planned_start_at->diffInMinutes($this->planned_end_at) - $this->planned_break_minutes);
    }
}
