<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会社カレンダー日。祝日属性(外部由来の事実、is_public_holiday)と会社の勤務区分判断
 * (schedule_state)を分離して持つ(docs/08-usecases-calendar-shift.md UC-C010)。
 *
 * 旧day_type/is_working_day/is_company_holidayはschedule_state+is_public_holidayから
 * 導出できるため廃止対象だが、既存参照箇所を壊さないよう2段階廃止で残す
 * (docs/16-database-schema.md参照)。
 */
#[Fillable(['calendar_id', 'date', 'day_type', 'is_working_day', 'is_legal_holiday', 'is_company_holiday', 'is_public_holiday', 'public_holiday_name', 'schedule_state', 'note'])]
class CompanyCalendarDay extends Model
{
    public const SCHEDULE_WORK = 'WORK';

    public const SCHEDULE_OFF = 'OFF';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_working_day' => 'boolean',
            'is_legal_holiday' => 'boolean',
            'is_company_holiday' => 'boolean',
            'is_public_holiday' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CompanyCalendarYear, $this>
     */
    public function year(): BelongsTo
    {
        return $this->belongsTo(CompanyCalendarYear::class, 'calendar_id');
    }

    /**
     * @return HasMany<CompanyCalendarDaySource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(CompanyCalendarDaySource::class, 'company_calendar_day_id');
    }
}
