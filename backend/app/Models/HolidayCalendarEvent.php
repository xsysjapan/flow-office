<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 祝日iCalendar同期結果 (docs/16-database-schema.md holiday_calendar_events、UC-C012)。
 * 同期処理自体は次のタスクで実装する。
 */
#[Fillable(['id', 'holiday_calendar_source_id', 'date', 'name', 'ics_uid', 'synced_at'])]
class HolidayCalendarEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HolidayCalendarSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendarSource::class, 'holiday_calendar_source_id');
    }
}
