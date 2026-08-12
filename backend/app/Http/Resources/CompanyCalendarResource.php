<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `weekday_holiday_pattern`はISO曜日"1"〜"7"をキーに持つ連想配列であり、キーが全て数字
 * (文字列)であるため、`#[PreserveKeys]`を付けないとJsonResourceの`filter()`が
 * 「数値キーのみの配列=リスト」とみなして`array_values()`で0始まりに詰め直してしまう
 * (Illuminate\Http\Resources\ConditionallyLoadsAttributes::removeMissingValues参照)。
 */
#[PreserveKeys]
class CompanyCalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'week_starts_on' => $this->week_starts_on,
            'fiscal_year_start_month' => $this->fiscal_year_start_month,
            'fiscal_year_start_day' => $this->fiscal_year_start_day,
            'holiday_calendar_source_id' => $this->holiday_calendar_source_id,
            'weekday_holiday_pattern' => $this->effectiveWeekdayHolidayPattern(),
            'is_default' => $this->is_default,
            'status' => $this->status,
        ];
    }
}
