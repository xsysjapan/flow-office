<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkCalendarDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'day_type' => $this->day_type,
            'is_working_day' => $this->is_working_day,
            'is_legal_holiday' => $this->is_legal_holiday,
            'is_company_holiday' => $this->is_company_holiday,
            'note' => $this->note,
        ];
    }
}
