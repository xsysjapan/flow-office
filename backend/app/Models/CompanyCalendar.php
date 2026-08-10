<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会社カレンダー本体 (docs/08-usecases-calendar-shift.md UC-C009)。
 *
 * 年度に依存しない継続設定のみを持つ。年度依存フィールド(fiscal_year/starts_on/ends_on/
 * status等)は`CompanyCalendarYear`が持つ(旧: 本テーブルが直接保持していたが分離した)。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'name', 'week_starts_on', 'timezone', 'fiscal_year_start_month', 'fiscal_year_start_day', 'is_default', 'status', 'holiday_calendar_source_id'])]
class CompanyCalendar extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return HasMany<CompanyCalendarYear, $this>
     */
    public function years(): HasMany
    {
        return $this->hasMany(CompanyCalendarYear::class, 'company_calendar_id');
    }

    /**
     * @return BelongsTo<HolidayCalendarSource, $this>
     */
    public function holidayCalendarSource(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendarSource::class, 'holiday_calendar_source_id');
    }
}
