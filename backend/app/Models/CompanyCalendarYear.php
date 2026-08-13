<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会社カレンダー年度 (docs/08-usecases-calendar-shift.md UC-C009)。
 *
 * `company_calendars`(本体)1件に対する年度ごとの版。`starts_on`/`ends_on`は作成時点の
 * 本体の`fiscal_year_start_month`/`fiscal_year_start_day`から計算した確定値であり、
 * 本体側の設定を後から変更しても遡って変わらない。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれる。
 */
#[Fillable(['id', 'company_calendar_id', 'fiscal_year', 'starts_on', 'ends_on', 'status', 'generated_from', 'published_at', 'published_by_user_id'])]
class CompanyCalendarYear extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompanyCalendar, $this>
     */
    public function companyCalendar(): BelongsTo
    {
        return $this->belongsTo(CompanyCalendar::class, 'company_calendar_id');
    }

    /**
     * @return HasMany<CompanyCalendarDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(CompanyCalendarDay::class, 'calendar_id');
    }
}
