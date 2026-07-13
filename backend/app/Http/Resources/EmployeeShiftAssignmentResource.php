<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeShiftAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'work_date' => $this->work_date?->toDateString(),
            'work_style_id' => $this->work_style_id,
            'shift_pattern_id' => $this->shift_pattern_id,
            'day_type' => $this->day_type,
            'is_working_day' => $this->is_working_day,
            'is_legal_holiday' => $this->is_legal_holiday,
            'is_company_holiday' => $this->is_company_holiday,
            'planned_start_at' => $this->planned_start_at?->toIso8601String(),
            'planned_end_at' => $this->planned_end_at?->toIso8601String(),
            'planned_break_minutes' => $this->planned_break_minutes,
            'is_published' => $this->is_published,
            'is_manually_overridden' => $this->is_manually_overridden,
        ];
    }
}
