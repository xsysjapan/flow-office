<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompensatoryLeaveGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'attendance_day_id' => $this->attendance_day_id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'granted_days' => (float) $this->granted_days,
            'granted_minutes' => $this->granted_minutes,
            'used_days' => (float) $this->used_days,
            'used_minutes' => $this->used_minutes,
            'remaining_days' => (float) $this->remaining_days,
            'remaining_minutes' => $this->remaining_minutes,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'expires_on' => $this->expires_on?->toDateString(),
        ];
    }
}
