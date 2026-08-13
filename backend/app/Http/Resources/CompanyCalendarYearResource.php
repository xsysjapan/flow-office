<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyCalendarYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_calendar_id' => $this->company_calendar_id,
            'fiscal_year' => $this->fiscal_year,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'generated_from' => $this->generated_from,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by_user_id' => $this->published_by_user_id,
        ];
    }
}
