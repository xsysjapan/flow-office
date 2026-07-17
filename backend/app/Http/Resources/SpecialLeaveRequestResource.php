<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialLeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'special_leave_type_id' => $this->special_leave_type_id,
            'special_leave_type_name' => $this->whenLoaded('specialLeaveType', fn () => $this->specialLeaveType->name),
            'status' => $this->status,
            'leave_type' => $this->leave_type,
            'target_date' => $this->target_date?->toDateString(),
            'hours' => $this->hours !== null ? (float) $this->hours : null,
            'requested_days' => (float) $this->requested_days,
            'reason' => $this->reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
