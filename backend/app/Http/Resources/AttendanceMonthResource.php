<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceMonthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'year_month' => $this->year_month,
            'status' => $this->status,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'snapshot' => $this->snapshot_json,
        ];
    }
}
