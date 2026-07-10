<?php

namespace App\Http\Resources;

use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendancePunchResource extends JsonResource
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
            'punch_type' => $this->punch_type,
            'punched_at' => LocalDateTime::formatWithOffsetMinutes($this->punched_at, $this->utc_offset_minutes),
            'source' => $this->source,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
