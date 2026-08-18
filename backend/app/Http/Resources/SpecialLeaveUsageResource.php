<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialLeaveUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'used_on' => $this->used_on?->toDateString(),
            'used_days' => (float) $this->used_days,
            'used_minutes' => $this->used_minutes,
            'usage_type' => $this->usage_type,
            'is_confirmed' => (bool) $this->is_confirmed,
            'special_leave_grant_id' => $this->special_leave_grant_id,
            'special_leave_request_id' => $this->special_leave_request_id,
            'request_status' => $this->whenLoaded('request', fn () => $this->request?->status),
        ];
    }
}
