<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialLeaveGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'special_leave_type_id' => $this->special_leave_type_id,
            'special_leave_type_name' => $this->whenLoaded('specialLeaveType', fn () => $this->specialLeaveType->name),
            'granted_on' => $this->granted_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'granted_days' => (float) $this->granted_days,
            'used_days' => (float) $this->used_days,
            'remaining_days' => (float) $this->remaining_days,
            'grant_reason' => $this->grant_reason,
        ];
    }
}
