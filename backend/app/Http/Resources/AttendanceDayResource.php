<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDayResource extends JsonResource
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
            'status' => $this->status,
            'actual_start_at' => $this->actual_start_at?->toIso8601String(),
            'actual_end_at' => $this->actual_end_at?->toIso8601String(),
            'work_type' => $this->work_type,
            'note' => $this->note,
            'is_locked' => $this->isLocked(),
            // today()でその日の勤務予定を一時的に載せている場合のみ含める(UC-A001 手順2)。
            'planned_start_at' => $this->planned_start_at,
            'planned_end_at' => $this->planned_end_at,
            'breaks' => AttendanceBreakResource::collection($this->whenLoaded('breaks')),
            'calculation' => $this->whenLoaded('calculation', fn () => $this->calculation ? new AttendanceDailyCalculationResource($this->calculation) : null),
        ];
    }
}
