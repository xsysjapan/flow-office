<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 年度カレンダー (docs/08-usecases-calendar-shift.md UC-C001)。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'name', 'fiscal_year', 'starts_on', 'ends_on', 'week_starts_on', 'status'])]
class WorkCalendar extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

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
