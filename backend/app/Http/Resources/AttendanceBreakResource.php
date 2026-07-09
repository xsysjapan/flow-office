<?php

namespace App\Http\Resources;

use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceBreakResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $ownerTimezone = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $this->ownerTimezone ?? config('app.timezone');

        return [
            'id' => $this->id,
            'break_start_at' => LocalDateTime::toIso8601($this->break_start_at, $timezone),
            'break_end_at' => LocalDateTime::toIso8601($this->break_end_at, $timezone),
        ];
    }
}
