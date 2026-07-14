<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftPatternResource extends JsonResource
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
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'crosses_midnight' => $this->crosses_midnight,
            'break_minutes' => $this->break_minutes,
            'break_start_time' => $this->break_start_time,
            'break_end_time' => $this->break_end_time,
            'prescribed_work_minutes' => $this->prescribed_work_minutes,
        ];
    }
}
