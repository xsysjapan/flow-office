<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceImportSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'target_month' => $this->target_month,
            'status' => $this->status,
            'source_file_name' => $this->source_file_name,
            'monthly_attendance_draft_id' => $this->monthly_attendance_draft_id,
            'items' => $this->whenLoaded('items', fn () => AttendanceImportItemResource::collection($this->items)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
