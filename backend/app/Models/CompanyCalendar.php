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
#[Fillable(['id', 'name', 'week_starts_on', 'timezone', 'fiscal_year_start_month', 'fiscal_year_start_day', 'is_default', 'status', 'holiday_calendar_source_id', 'weekday_holiday_pattern'])]
class CompanyCalendar extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * 既定の曜日休日パターン(現行の固定ロジックと同じ: 月〜金=勤務日、土=所定休日、
     * 日=法定休日かつ所定休日)。`weekday_holiday_pattern`が未設定の会社カレンダーは
     * この既定値にフォールバックする(既存の挙動を変えない)。
     *
     * @var array<string, string>
     */
    public const DEFAULT_WEEKDAY_HOLIDAY_PATTERN = [
        '1' => 'working',
        '2' => 'working',
        '3' => 'working',
        '4' => 'working',
        '5' => 'working',
        '6' => 'company_holiday',
        '7' => 'legal_holiday',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'weekday_holiday_pattern' => 'array',
        ];
    }

    /**
     * 曜日ごとの休日区分(ISO曜日"1"〜"7" => working|company_holiday|legal_holiday)を
     * 常に7キー揃った形で返す。`weekday_holiday_pattern`が未設定ならデフォルトへ
     * フォールバックする。
     *
     * @return array<string, string>
     */
    public function effectiveWeekdayHolidayPattern(): array
    {
        return $this->weekday_holiday_pattern ?? self::DEFAULT_WEEKDAY_HOLIDAY_PATTERN;
    }

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
