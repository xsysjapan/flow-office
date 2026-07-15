<?php

namespace App\Http\Resources;

use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceLeaveSegmentResource extends JsonResource
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
            'category' => $this->category,
            'start_at' => LocalDateTime::formatWithOffsetMinutes($this->start_at, $this->utcOffsetMinutes),
            'end_at' => LocalDateTime::formatWithOffsetMinutes($this->end_at, $this->utcOffsetMinutes),
            'note' => $this->note,
        ];
    }
}
