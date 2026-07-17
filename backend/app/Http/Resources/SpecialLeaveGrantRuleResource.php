<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialLeaveGrantRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'special_leave_type_id' => $this->special_leave_type_id,
            'special_leave_type_name' => $this->whenLoaded('specialLeaveType', fn () => $this->specialLeaveType->name),
            'name' => $this->name,
            'work_style_id' => $this->work_style_id,
            'min_attendance_rate' => $this->min_attendance_rate,
            'first_grant_after_months' => $this->first_grant_after_months,
            'grant_cycle_months' => $this->grant_cycle_months,
            'expires_after_months' => $this->expires_after_months,
            'is_active' => $this->is_active,
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => [
                'continuous_service_months' => $step->continuous_service_months,
                'grant_days' => $step->grant_days,
            ])),
        ];
    }
}
