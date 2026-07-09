<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceBreakResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'break_start_at' => $this->break_start_at?->toIso8601String(),
            'break_end_at' => $this->break_end_at?->toIso8601String(),
        ];
    }
}
