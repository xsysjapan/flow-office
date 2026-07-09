<?php

namespace App\Http\Resources;

use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceBreakResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $utcOffsetMinutes = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'break_start_at' => LocalDateTime::formatWithOffsetMinutes($this->break_start_at, $this->utcOffsetMinutes),
            'break_end_at' => LocalDateTime::formatWithOffsetMinutes($this->break_end_at, $this->utcOffsetMinutes),
        ];
    }
}
