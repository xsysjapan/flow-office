<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        ];
    }
}
