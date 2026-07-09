<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 年度カレンダー (docs/08-usecases-calendar-shift.md UC-C001)。
 */
#[Fillable(['name', 'fiscal_year', 'starts_on', 'ends_on', 'week_starts_on', 'status'])]
class WorkCalendar extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return HasMany<WorkCalendarDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(WorkCalendarDay::class, 'calendar_id');
    }
}
