<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 祝日iCalendarソース (docs/16-database-schema.md holiday_calendar_sources、UC-C012)。
 * 同期処理は`App\Domain\Attendance\Services\HolidayCalendarSynchronizer`にまとめる。
 */
#[Fillable(['id', 'name', 'ics_url', 'sync_status', 'last_synced_at', 'last_error', 'disabled_at'])]
class HolidayCalendarSource extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @return HasMany<HolidayCalendarEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(HolidayCalendarEvent::class, 'holiday_calendar_source_id');
    }
}
