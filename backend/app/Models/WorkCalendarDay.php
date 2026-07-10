<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['calendar_id', 'date', 'day_type', 'is_working_day', 'is_legal_holiday', 'is_company_holiday', 'note'])]
class WorkCalendarDay extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_working_day' => 'boolean',
            'is_legal_holiday' => 'boolean',
            'is_company_holiday' => 'boolean',
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
