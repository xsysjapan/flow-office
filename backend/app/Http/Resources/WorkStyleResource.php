<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkStyleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'work_time_system' => $this->work_time_system,
            'prescribed_daily_minutes' => $this->prescribed_daily_minutes,
            'prescribed_weekly_minutes' => $this->prescribed_weekly_minutes,
            'default_start_time' => $this->default_start_time,
            'default_end_time' => $this->default_end_time,
            'default_break_minutes' => $this->default_break_minutes,
            'calendar_id' => $this->calendar_id,
            'is_shift_based' => $this->is_shift_based,
        ];
    }
}
