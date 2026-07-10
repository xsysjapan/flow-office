<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 社員別勤務予定 (docs/03-architecture.md 3.3: 勤怠の正の一つ)。
 */
#[Fillable(['user_id', 'work_date', 'work_style_id', 'day_type', 'is_working_day', 'is_legal_holiday', 'is_company_holiday', 'planned_start_at', 'planned_end_at', 'planned_break_minutes'])]
class EmployeeShiftAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_working_day' => 'boolean',
            'is_legal_holiday' => 'boolean',
            'is_company_holiday' => 'boolean',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
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
}
